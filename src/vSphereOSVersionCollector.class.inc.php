<?php
// Copyright (C) 2014-2015 Combodo SARL
//
//   This application is free software; you can redistribute it and/or modify	
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with this application. If not, see <http://www.gnu.org/licenses/>

class vSphereOSVersionCollector extends Collector
{
	protected $idx;
	protected $aOSVersion;

	/**
	 * @inheritdoc
	 */
	public function AttributeIsOptional($sAttCode)
	{
		// make Lifecycle Management optional
		if ($sAttCode == 'eol') return true;
		if ($sAttCode == 'eomss') return true;
		if ($sAttCode == 'eoesu') return true;

		return parent::AttributeIsOptional($sAttCode);
	}

	public function Prepare()
	{
		$bRet = parent::Prepare();
		$this->idx = 0;
		$aTmp = array();

		if (class_exists('vSphereVirtualMachineCollector')) {
			// Collect the different couples {os_family, os_version} from the virtual machines
			$aVMInfos = vSphereVirtualMachineCollector::CollectVMInfos();
			foreach ($aVMInfos as $aVM) {
				if (array_key_exists('osfamily_id', $aVM) && ($aVM['osfamily_id'] != null)) {
					// unique ID : Family + version
					$aTmp[$aVM['osfamily_id'].'_'.$aVM['osversion_id']] = array(
						'name' => $aVM['osversion_id'],
						'osfamily_id' => $aVM['osfamily_id']
					);
				}
			}
		}

		if (class_exists('vSphereHypervisorCollector')) {
			// Add the different couples {os_family, os_version} from the hypervisors
			$aHypervisors = vSphereHypervisorCollector::GetHypervisors();
			foreach ($aHypervisors as $aHV) {
				if (array_key_exists('osfamily_id', $aHV) && ($aHV['osfamily_id'] != null)) {
					// unique ID : Family + version
					$aTmp[$aHV['osfamily_id'].'_'.$aHV['osversion_id']] = array(
						'name' => $aHV['osversion_id'],
						'osfamily_id' => $aHV['osfamily_id']
					);
				}
			}
		}
		
		// Build a zero-based array
		$this->aOSVersion = [];
		foreach($aTmp as $aData)
		{
			$this->aOSVersion[] = $aData;
		}
		return $bRet;
	}
	
	public function Fetch()
	{
        if (is_null($this->aOSVersion)) {
            return false;
        }
		if ($this->idx < count($this->aOSVersion))
		{
			$aOSVersion = $this->aOSVersion[$this->idx++];
			return array(
					'primary_key' => $aOSVersion['osfamily_id'].'_'.$aOSVersion['name'],
					'name' => $aOSVersion['name'], 
					'osfamily_id' => $aOSVersion['osfamily_id']);
		}
		return false;
	}
}
