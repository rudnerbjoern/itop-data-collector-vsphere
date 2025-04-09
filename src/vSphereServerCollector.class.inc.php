<?php
require_once(APPROOT . 'collectors/src/vSphereCollector.class.inc.php');

class vSphereServerCollector extends vSphereCollector
{
	protected $idx;
	protected $oOSVersionLookup;
	protected $oModelLookup;
	protected $oIPAddressLookup;
	protected static $aServers;

	/**
	 * @inheritdoc
	 */
	public function CheckToLaunch(array $aOrchestratedCollectors): bool
	{
		if (parent::CheckToLaunch($aOrchestratedCollectors)) {
			if (array_key_exists('vSphereHypervisorCollector', $aOrchestratedCollectors) && ($aOrchestratedCollectors['vSphereHypervisorCollector'] == true)) {
				return true;
			} else {
				Utils::Log(LOG_INFO, '> vSphereServerCollector will not be launched as vSphereHypervisorCollector is required but is not launched');
			}
		}

		return false;
	}

	/**
	 * @inheritdoc
	 */
	public function AttributeIsOptional($sAttCode)
	{
		if ($sAttCode == 'services_list') return true;
		if ($sAttCode == 'providercontracts_list') return true;

		if ($this->oCollectionPlan->IsTeemIpInstalled()) {
			if ($sAttCode == 'managementip') return true;
			if ($sAttCode == 'managementip_id') return false;
		} else {
			if ($sAttCode == 'managementip') return false;
			if ($sAttCode == 'managementip_id') return true;
		}

		if ($this->oCollectionPlan->IsCbdVMwareDMInstalled()) {
			if ($sAttCode == 'hostid') return false;
		} else {
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

		// Workstation ID is optional
		if ($sAttCode == 'workstation_id') return true;

		// PowerSockets are optional
		if ($sAttCode == 'powerAsocket_id') return true;
		if ($sAttCode == 'powerBsocket_id') return true;

		// CPU fields are optional, if not installed
		if ($this->oCollectionPlan->IsCpuExtensionInstalled()) {
			if ($sAttCode == 'cpu_sockets') return false;
			if ($sAttCode == 'cpu_cores') return false;
			if ($sAttCode == 'cpu_count') return false;
		} else {
			if ($sAttCode == 'cpu_sockets') return true;
			if ($sAttCode == 'cpu_cores') return true;
			if ($sAttCode == 'cpu_count') return true;
		}

		// Datacenter View is optional
		if ($sAttCode == 'position_p') return true;
		if ($sAttCode == 'position_v') return true;
		if ($sAttCode == 'position_h') return true;
		if ($sAttCode == 'nb_u') return true;
		if ($sAttCode == 'nb_cols') return true;
		if ($sAttCode == 'zero_u') return true;
		if ($sAttCode == 'expected_power_input') return true;
		if ($sAttCode == 'weight') return true;

		// Monitoring is optional, if not installed
		if ($sAttCode == 'monitoringstatus') return true;
		if ($sAttCode == 'monitoringparameter') return true;
		if ($sAttCode == 'monitoringprobe_id') return true;
		if ($this->oCollectionPlan->IsMonitoringExtensionInstalled()) {
			if ($sAttCode == 'monitoringip_id') return false;
		} else {
			if ($sAttCode == 'monitoringip_id') return true;
		}

		// Express Service Code is optional
		if ($sAttCode == 'express_service_code') return true;

		return parent::AttributeIsOptional($sAttCode);
	}

	/**
	 * @return mixed
	 * @throws \Exception
	 */
	public static function CollectServerInfos()
	{
		if (static::$aServers === null) {
			if (class_exists('vSphereHypervisorCollector')) {
				$aHypervisors = vSphereHypervisorCollector::GetHypervisors();
				foreach ($aHypervisors as $aHyperV) {
					static::$aServers[] = static::DoCollectServer($aHyperV);
				}
			} else {
				static::$aServers = [];
			}
		}
		utils::Log(LOG_DEBUG, "End of collection of Servers information.");

		return static::$aServers;
	}

	/**
	 * @param $aHyperV
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected static function DoCollectServer($aHyperV)
	{
		$sDefaultOrg = Utils::GetConfigurationValue('default_org_id');
		$aData = array(
			'primary_key' => $aHyperV['id'],
			'name' => $aHyperV['name'],
			'org_id' => $sDefaultOrg,
			'status' => 'production',
			'brand_id' => $aHyperV['brand_id'],
			'model_id' => $aHyperV['model_id'],
			'osfamily_id' => $aHyperV['osfamily_id'],
			'osversion_id' => $aHyperV['osversion_id'],
			'cpu' => $aHyperV['cpu'],
			'ram' => $aHyperV['ram'],
			'cpu_sockets' => $aHyperV['cpu_sockets'],
			'cpu_cores' => $aHyperV['cpu_cores'],
			'serialnumber' => $aHyperV['serialnumber'],
		);

		// Add the custom fields (if any)
		foreach (vSphereHypervisorCollector::GetCustomFields(__CLASS__) as $sAttCode => $sFieldDefinition) {
			$aData[$sAttCode] = $aHyperV['server-custom-' . $sAttCode];
		}

		$oCollectionPlan = vSphereCollectionPlan::GetPlan();
		if ($oCollectionPlan->IsCbdVMwareDMInstalled()) {
			$aData['hostid'] = $aHyperV['id'];
		}
		if ($oCollectionPlan->IsTeemIpInstalled()) {
			$aTeemIpOptions = Utils::GetConfigurationValue('teemip_discovery', array());
			$bCollectIps = ($aTeemIpOptions['collect_ips'] == 'yes') ? true : false;
			$bCollectIPv6Addresses = ($aTeemIpOptions['manage_ipv6'] == 'yes') ? true : false;

			$sName = $aHyperV['managementip'] ?? '';
			$sIP = '';
			if ($bCollectIps == 'yes') {
				// Check if name has IPv4 or "IPv6" format
				if (filter_var($sName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (($bCollectIPv6Addresses == 'yes') && filter_var($sName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
					$sIP = $sName;
				}
			}

			unset($aData['managementip']);
			$aData['managementip_id'] = $sIP;

			if ($oCollectionPlan->IsMonitoringExtensionInstalled()) {
				$aData['monitoringip_id'] = $sIP;
			}
		}

		return $aData;
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

		static::CollectServerInfos();

		$this->idx = 0;

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function Fetch()
	{
		if ($this->idx < count(static::$aServers)) {
			$aServer = static::$aServers[$this->idx++];

			return $this->DoFetch($aServer);
		}

		return false;
	}

	/**
	 * @param $aServer
	 *
	 * @return mixed
	 */
	protected function DoFetch($aServer)
	{
		return $aServer;
	}

	/**
	 * @inheritdoc
	 */
	protected function MustProcessBeforeSynchro()
	{
		// We must reprocess the CSV data obtained from vSphere
		// to lookup the Brand/Model and OSFamily/OSVersion in iTop
		return true;
	}

	/**
	 * @inheritdoc
	 */
	protected function InitProcessBeforeSynchro()
	{
		// Retrieve the identifiers of the OSVersion since we must do a lookup based on two fields: Family + Version
		// which is not supported by the iTop Data Synchro... so let's do the job of an ETL
		$this->oOSVersionLookup = new LookupTable('SELECT OSVersion', array('osfamily_id_friendlyname', 'name'));

		// Retrieve the identifiers of the Model since we must do a lookup based on two fields: Brand + Model
		// which is not supported by the iTop Data Synchro... so let's do the job of an ETL
		$this->oModelLookup = new LookupTable('SELECT Model', array('brand_id_friendlyname', 'name'));

		if ($this->oCollectionPlan->IsTeemIpInstalled()) {
			if ($this->oCollectionPlan->GetTeemIpOption('lookup_ips_with_organization')) {
				$this->oIPAddressLookup = new LookupTable('SELECT IPAddress', array('org_name', 'friendlyname'));
			} else {
				$this->oIPAddressLookup = new LookupTable('SELECT IPAddress', array('friendlyname'));
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex)
	{
		// Process each line of the CSV
		$this->oOSVersionLookup->Lookup($aLineData, array('osfamily_id', 'osversion_id'), 'osversion_id', $iLineIndex);
		$this->oModelLookup->Lookup($aLineData, array('brand_id', 'model_id'), 'model_id', $iLineIndex);
		if ($this->oCollectionPlan->IsTeemIpInstalled()) {
			if ($this->oCollectionPlan->GetTeemIpOption('lookup_ips_with_organization')) {
				$this->oIPAddressLookup->Lookup($aLineData, array('org_id', 'managementip_id'), 'managementip_id', $iLineIndex);
			} else {
				$this->oIPAddressLookup->Lookup($aLineData, array('managementip_id'), 'managementip_id', $iLineIndex);
			}
			if ($this->oCollectionPlan->IsMonitoringExtensionInstalled()) {
				if ($this->oCollectionPlan->GetTeemIpOption('lookup_ips_with_organization')) {
					$this->oIPAddressLookup->Lookup($aLineData, array('org_id', 'monitoringip_id'), 'monitoringip_id', $iLineIndex);
				} else {
					$this->oIPAddressLookup->Lookup($aLineData, array('monitoringip_id'), 'monitoringip_id', $iLineIndex);
				}
			}
		}
	}
}
