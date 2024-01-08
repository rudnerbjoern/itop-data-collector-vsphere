<?php
require_once(APPROOT.'collectors/src/vSphereCollector.class.inc.php');

class vSphereOSFamilyCollector extends vSphereCollector
{
	protected $idx;
	protected $aOSFamily;

	/**
	 * @inheritdoc
	 */
	public function CheckToLaunch(array $aOrchestratedCollectors): bool
	{
		if (parent::CheckToLaunch($aOrchestratedCollectors)) {
			if (array_key_exists('vSphereVirtualMachineCollector', $aOrchestratedCollectors) && ($aOrchestratedCollectors['vSphereVirtualMachineCollector'] == true)) {
				return true;
			} else {
				Utils::Log(LOG_INFO, '> vSphereOSFamilyCollector will not be launched as vSphereVirtualMachineCollector is required but is not launched');
			}
		}

		return false;
	}

	public function Prepare()
	{
		$bRet = parent::Prepare();
		$this->idx = 0;
		// Get the different OS Family values from the Virtual Machines
		$aVMInfos = vSphereVirtualMachineCollector::CollectVMInfos();
		$aTmp = array();
		foreach ($aVMInfos as $aVM) {
			if (array_key_exists('osfamily_id', $aVM) && ($aVM['osfamily_id'] != null)) {
				$aTmp[$aVM['osfamily_id']] = true;
			}
		}
		// Add the different OS Family values from the Hypervisors
		$aHypervisors = vSphereHypervisorCollector::GetHypervisors();
		foreach ($aHypervisors as $aHV) {
			$aTmp[$aHV['osfamily_id']] = true;
		}
		$this->aOSFamily = array_keys($aTmp);

		return $bRet;
	}

	public function Fetch()
	{
		if ($this->idx < count($this->aOSFamily)) {
			$sOSFamily = $this->aOSFamily[$this->idx++];

			return array('primary_key' => $sOSFamily, 'name' => $sOSFamily);
		}

		return false;
	}
}