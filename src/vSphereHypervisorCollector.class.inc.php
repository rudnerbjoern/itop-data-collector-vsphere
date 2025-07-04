<?php
require_once(APPROOT.'collectors/src/vSphereCollector.class.inc.php');

class vSphereHypervisorCollector extends vSphereCollector
{
	protected int $idx;
	protected array $aHypervisorFields;
    static protected bool $bHypervisorsCollected = false;
	static protected array $aHypervisors = [];
    static protected array $aLnkDatastoreToVHosts;

	public function __construct()
	{
		parent::__construct();
		$aDefaultFields = array('primary_key', 'name', 'org_id', 'status', 'server_id', 'farm_id', 'uuid', 'hostid');
		$aCustomFields = array_keys(static::GetCustomFields(__CLASS__));
		$this->aHypervisorFields = array_merge($aDefaultFields, $aCustomFields);
        self::$aLnkDatastoreToVHosts = [];
	}

    /**
     * @inheritdoc
     */
	public function AttributeIsOptional($sAttCode)
	{
		if ($sAttCode == 'services_list') return true;
		if ($sAttCode == 'providercontracts_list') return true;
		if ($this->oCollectionPlan->IsCbdVMwareDMInstalled()) {
			if ($sAttCode == 'uuid') return false;
			if ($sAttCode == 'hostid') return false;
		} else {
			if ($sAttCode == 'uuid') return true;
			if ($sAttCode == 'hostid') return true;
		}

		// System Landscape is optional
		if ($sAttCode == 'system_landscape') return true;

		// Cost Center is optional
		if ($sAttCode == 'costcenter_id') return true;

		//  Backup Management is optional
		if ($sAttCode == 'backupmethod') return true;
		if ($sAttCode == 'backupdescription') return true;

		// Patch Management is optional
		if ($sAttCode == 'patchmethod_id') return true;
		if ($sAttCode == 'patchgroup_id') return true;
		if ($sAttCode == 'patchreboot_id') return true;

		// Risk Management is optional
		if ($sAttCode == 'rm_confidentiality') return true;
		if ($sAttCode == 'rm_integrity') return true;
		if ($sAttCode == 'rm_availability') return true;
		if ($sAttCode == 'rm_authenticity') return true;
		if ($sAttCode == 'rm_nonrepudiation') return true;
		if ($sAttCode == 'bcm_rto') return true;
		if ($sAttCode == 'bcm_rpo') return true;
		if ($sAttCode == 'bcm_mtd') return true;

		// Monitoring is optional, if not installed
		if ($sAttCode == 'monitoringstatus') return true;
		if ($sAttCode == 'monitoringparameter') return true;
		if ($sAttCode == 'monitoringprobe_id') return true;
		if ($sAttCode == 'monitoringip_id') return true;

		return parent::AttributeIsOptional($sAttCode);
	}

	public static function GetHypervisors()
	{
		if (!self::$bHypervisorsCollected) {
            self::$bHypervisorsCollected = true;
			$oBrandMappings = new MappingTable('brand_mapping');
			$oModelMappings = new MappingTable('model_mapping');
			$oOSFamilyMappings = new MappingTable('os_family_mapping');
			$oOSVersionMappings = new MappingTable('os_version_mapping');

			$sVSphereServer = Utils::GetConfigurationValue('vsphere_uri', '');
			$sLogin = Utils::GetConfigurationValue('vsphere_login', '');
			$sPassword = Utils::GetConfigurationValue('vsphere_password', '');
			$sDefaultOrg = Utils::GetConfigurationValue('default_org_id');
			$sVMCPUAttribute = 'numCpuCores';
			$aVMParams = Utils::GetConfigurationValue('hypervisor', []);
			if (!empty($aVMParams) && array_key_exists('cpu_attribute', $aVMParams) && ($aVMParams['cpu_attribute'] != '')) {
				$sVMCPUAttribute = $aVMParams['cpu_attribute'];
			}

			static::InitVmwarephp();
			if (!static::CheckSSLConnection($sVSphereServer)) {
				throw new Exception("Cannot connect to https://$sVSphereServer. Aborting.");
			}

			if (class_exists('vSphereFarmCollector')) {
				$aFarms = vSphereFarmCollector::GetFarms();
			} else {
				$aFarms = [];
			}

			self::$aHypervisors = array();
			$vhost = new \Vmwarephp\Vhost($sVSphereServer, $sLogin, $sPassword);

			$aHypervisors = $vhost->findAllManagedObjects('HostSystem', array('datastore', 'hardware', 'summary'));

			foreach ($aHypervisors as $oHypervisor) {
                if (is_null($oHypervisor->runtime)) {
                    Utils::Log(LOG_INFO, "Skipping Hypervisor {$oHypervisor->name} which is NOT connected (runtime is null)");
                    continue;
                }
				if ($oHypervisor->runtime->connectionState !== 'connected') {
					// The documentation says that 'config' ".. might not be available for a disconnected host"
					// A customer reported that trying to access ->config->... causes a segfault !!
					// So let's skip such hypervisors for now
					Utils::Log(LOG_INFO, "Skipping Hypervisor {$oHypervisor->name} which is NOT connected (runtime->connectionState = '{$oHypervisor->runtime->connectionState}')");
					continue;
				}

				$sFarmName = '';
				// Is the hypervisor part of a farm ?

				foreach ($aFarms as $aFarm) {
					if (in_array($oHypervisor->name, $aFarm['hosts'])) {
						$sFarmName = $aFarm['name'];
						break; // farm found
					}
				}

				// get the Hypervisor name and only use the hostname (it might be FQDN)
				$sSeverName = static::extractHostname($oHypervisor->name);

				// get the serial number is not that easy...
				$sSerialNumber = 'unknown';
				foreach ($oHypervisor->hardware->systemInfo->otherIdentifyingInfo as $oTstSN) {
					if ($oTstSN->identifierType->key == 'SerialNumberTag') {
						$sSerialNumber = $oTstSN->identifierValue;
						break;
					}
				}

				// management_ip quest
				$sManagementIp = '';
				foreach ($oHypervisor->config->option as $oTstIP) {
					if ($oTstIP->key == 'Vpx.Vpxa.config.host_ip') {
						$sManagementIp = $oTstIP->value;
						break;
					}
				}

				Utils::Log(LOG_DEBUG, "Server {$oHypervisor->name}: {$oHypervisor->hardware->systemInfo->vendor} {$oHypervisor->hardware->systemInfo->model}");
				Utils::Log(LOG_DEBUG, "Server software: {$oHypervisor->config->product->fullName} - API Version: {$oHypervisor->config->product->apiVersion}");

				$aHypervisorData = array(
					'id' => $oHypervisor->getReferenceId(),
					'primary_key' => $oHypervisor->getReferenceId(),
					'name' => $sSeverName,
					'org_id' => $sDefaultOrg,
					'brand_id' => $oBrandMappings->MapValue($oHypervisor->hardware->systemInfo->vendor, 'Other'),
					'model_id' => $oModelMappings->MapValue($oHypervisor->hardware->systemInfo->model, ''),
					'cpu' => ($oHypervisor->hardware->cpuInfo->$sVMCPUAttribute) ?? '',
					'cpu_sockets' => '',
					'cpu_cores' => '',
					'ram' => (int)($oHypervisor->hardware->memorySize / (1024*1024)),
					'osfamily_id' => $oOSFamilyMappings->MapValue($oHypervisor->config->product->name, 'Other'),
					'osversion_id' => $oOSVersionMappings->MapValue($oHypervisor->config->product->fullName, ''),
					'status' => 'production',
					'farm_id' => $sFarmName,
					'server_id' => $sSeverName,
					'serialnumber' => $sSerialNumber,
					'managementip' => $sManagementIp,
				);

				$oCollectionPlan = vSphereCollectionPlan::GetPlan();
				if ($oCollectionPlan->IsCbdVMwareDMInstalled()) {
					$aHypervisorData['uuid'] = ($oHypervisor->hardware->systemInfo->uuid) ?? '';
					$aHypervisorData['hostid'] = $oHypervisor->getReferenceId();

                    utils::Log(LOG_DEBUG, "Reading datastores...");
                    $aDatastores = $oHypervisor->datastore;
                    foreach ($aDatastores as $aDatastore) {
                        if (!is_null($aDatastore)) {
                            self::$aLnkDatastoreToVHosts[] = [
                                'datastore_id' => $aDatastore->getReferenceId(),
                                'virtualhost_id' => $aHypervisorData['name']
                            ];
                        }
                    }
				}

				// get extended CPU info
				if ($oCollectionPlan->IsCpuExtensionInstalled()) {
					$sCpuName = '';
					$iCpuSockets = (int)$oHypervisor->hardware->cpuInfo->numCpuPackages;
					$iCpuCores = (int)$oHypervisor->hardware->cpuInfo->numCpuCores;
					if ($iCpuSockets > 0) {
						$iCpuCores = (int)($oHypervisor->hardware->cpuInfo->numCpuCores / $iCpuSockets);
					}
					foreach ($oHypervisor->hardware->cpuPkg as $oTstCpu) {
						$sCpuName = $oTstCpu->description;
						break;
					}

					$aHypervisorData['cpu']  = $sCpuName;
					$aHypervisorData['cpu_sockets'] = $iCpuSockets;
					$aHypervisorData['cpu_cores'] = $iCpuCores;
				}

				foreach (static::GetCustomFields(__CLASS__) as $sAttCode => $sFieldDefinition) {
					$aHypervisorData[$sAttCode] = static::GetCustomFieldValue($oHypervisor, $sFieldDefinition);
				}

				// Hypervisors and Servers actually share the same collector mechanism
				foreach (static::GetCustomFields('vSphereServerCollector') as $sAttCode => $sFieldDefinition) {
					$aHypervisorData['server-custom-'.$sAttCode] = static::GetCustomFieldValue($oHypervisor, $sFieldDefinition);
				}

				self::$aHypervisors[] = $aHypervisorData;
			}
		}

		return self::$aHypervisors;
	}

	protected static function GetCustomFieldValue($oHypervisor, $sFieldDefinition)
	{
		$value = '';
		$aMatches = array();
		if (preg_match('/^hardware->systemInfo->otherIdentifyingInfo\\[(.+)\\]$/', $sFieldDefinition, $aMatches)) {
			$bFound = false;
			// Special case for HostSystemIdentificationInfo object
			foreach ($oHypervisor->hardware->systemInfo->otherIdentifyingInfo as $oValue) {
				if ($oValue->identifierType->key == $aMatches[1]) {
					$value = $oValue->identifierValue;
					$bFound = true;
					break;
				}
			}
			// Item not found
			if (!$bFound) {
				Utils::Log(LOG_WARNING, "Field $sFieldDefinition not found for Hypervisor '{$oHypervisor->name}'");
			}
		} else {
			eval('$value = $oHypervisor->'.$sFieldDefinition.';');
		}

		return $value;
	}

    /**
     * Get the datastores attached to the Hypervisor
     *
     * @return array
     */
    static public function GetDatastoreLnks()
    {
        return self::$aLnkDatastoreToVHosts;
    }

    /**
     * @inheritdoc
     */
    public function Prepare()
	{
		$bRet = parent::Prepare();
		if (!$bRet) {
			return false;
		}

		self::GetHypervisors();

		$this->idx = 0;

		return true;
	}
    /**
     * @inheritdoc
     */
	public function Fetch()
	{
        if (is_null(self::$aHypervisors)) {
            return false;
        }
		if ($this->idx < count(self::$aHypervisors)) {
			$aHV = self::$aHypervisors[$this->idx++];
			$aResult = array();
			foreach ($this->aHypervisorFields as $sAttCode) {
				if (!$this->AttributeIsOptional($sAttCode)) {
					$aResult[$sAttCode] = $aHV[$sAttCode];
				}
			}

			return $aResult;
		}

		return false;
	}

}
