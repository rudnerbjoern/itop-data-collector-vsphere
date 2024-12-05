<?php
require_once(APPROOT . 'collectors/src/vSphereCollector.class.inc.php');

class vSphereFarmCollector extends vSphereCollector
{
	protected $idx;
	static protected bool $bFarmsCollected = false;
	static protected array $aFarms = [];

	public function AttributeIsOptional($sAttCode)
	{
		// If the module Service Management for Service Providers is selected during the setup
		// there is no "services_list" attribute on VirtualMachines. Let's safely ignore it.
		if ($sAttCode == 'services_list') return true;

		// If the collector is connected to TeemIp standalone, there is no "providercontracts_list"
		// on Servers. Let's safely ignore it.
		if ($sAttCode == 'providercontracts_list') return true;

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

	static public function GetFarms()
	{
		if (!self::$bFarmsCollected) {
			self::$bFarmsCollected = true;
			$sVSphereServer = Utils::GetConfigurationValue('vsphere_uri', '');
			$sLogin = Utils::GetConfigurationValue('vsphere_login', '');
			$sPassword = Utils::GetConfigurationValue('vsphere_password', '');
			$sDefaultOrg = Utils::GetConfigurationValue('default_org_id');

			// Init VMware library and connection to VMware
			static::InitVmwarephp();
			if (!static::CheckSSLConnection($sVSphereServer)) {
				throw new Exception("Cannot connect to https://$sVSphereServer. Aborting.");
			}

			// Get farms
			$vhost = new \Vmwarephp\Vhost($sVSphereServer, $sLogin, $sPassword);
			$aFarms = $vhost->findAllManagedObjects('ClusterComputeResource', array('configurationEx'));
			self::$aFarms = array();

			foreach ($aFarms as $oFarm) {
				Utils::Log(LOG_DEBUG, 'Farm->name: ' . $oFarm->name);
				$aHosts = array();
				foreach ($oFarm->host as $oHost) {
					if (is_object($oHost)) {
						$aHosts[] = $oHost->name;
					}
				}

				self::$aFarms[] = array(
					'id' => $oFarm->name,
					'name' => $oFarm->name,
					'org_id' => $sDefaultOrg,
					'hosts' => $aHosts,
				);
			}
		}
		return self::$aFarms;
	}

	public function Prepare()
	{
		$bRet = parent::Prepare();
		if (!$bRet) return false;

		self::GetFarms();

		$this->idx = 0;
		return true;
	}

	public function Fetch()
	{
		if ($this->idx < count(self::$aFarms)) {
			$aFarm = self::$aFarms[$this->idx++];
			return array(
				'primary_key' => $aFarm['id'],
				'name' => $aFarm['name'],
				'org_id' => $aFarm['org_id'],
			);
		}
		return false;
	}
}
