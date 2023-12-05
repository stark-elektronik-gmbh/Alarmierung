<?php

declare(strict_types=1);

class StarkAlarmierung extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyInteger('ActivateDelay', 10);

        //Variables
        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 10);
        $this->EnableAction('Active');
        $this->RegisterVariableString('DelayDisplay', $this->Translate('Time to Activation'), '', 20);
        $this->RegisterVariableBoolean('Alert', $this->Translate('Alert'), '~Alert', 30);
        $this->EnableAction('Alert');
        $this->RegisterVariableString('ActiveSensors', $this->Translate('Active Sensors'), '~TextBox', 40);

        //Attributes
        $this->RegisterAttributeInteger('LastAlert', 0);

        //Timer
        $this->RegisterTimer('Delay', 0, 'SARM_Activate($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateDisplay', 0, 'SARM_UpdateDisplay($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $sensors = json_decode($this->ReadPropertyString('Sensors'));

        //DelayDispaly
        $this->SetBuffer('Active', json_encode($this->GetValue('Active')));
        $this->stopDelay();

        //Deleting all References
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        foreach ($sensors as $sensor) {
            $this->RegisterMessage($sensor->ID, VM_UPDATE);
            $this->RegisterReference($sensor->ID);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);

        if ($Data[1]) {
            IPS_LogMessage('SenderID',$SenderID);
            $this->triggerAlert($SenderID);
        }       
    }

    public function SetAlert(bool $Status)
    {
        SetValue($this->GetIDForIdent('Alert'), $Status);
        if (!$Status) {
            $this->deleteActive();
        }
    }

    public function GetLastAlertID()
    {
        return $this->ReadAttributeInteger('LastAlert');
    }

    public function SetActive(bool $Value)
    {
        SetValue($this->GetIDForIdent('Active'), $Value);
        if (!$Value) {
            $this->SetBuffer('Active', json_encode(false));
            $this->SetAlert(false);
            $this->stopDelay();
            return;
        }

        //Start activation process only if not already active
        if (!json_decode($this->GetBuffer('Active'))) {

            //Only start with delay when delay is > 0
            if ($this->ReadPropertyInteger('ActivateDelay') > 0) {
                $this->startDelay();
            } else {
                $this->SetBuffer('Active', json_encode(true));
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetActive($Value);
                break;
            case 'Alert':
                $this->SetAlert($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    public function Activate()
    {
        $this->SetBuffer('Active', json_encode(true));
        $this->stopDelay();
    }

    public function UpdateDisplay()
    {
        if (json_decode($this->GetBuffer('TimeActivated')) <= time()) {
            $this->stopDelay();
            return;
        }
        $secondsRemaining = json_decode($this->GetBuffer('TimeActivated')) - time();
        $this->SetValue('DelayDisplay', sprintf('%02d:%02d:%02d', ($secondsRemaining / 3600), ($secondsRemaining / 60 % 60), $secondsRemaining % 60));
    }

    public function GetConfigurationForm()
    {
        $formdata = json_decode(file_get_contents(__DIR__ . '/form.json'));
        return json_encode($formdata);
    }

    private function triggerAlert($sensorID)
    {

        //Only enable alarming if our module is active
        if (!json_decode($this->GetBuffer('Active'))) {
            return;
        }

            $activeSensors = $this->GetValue('ActiveSensors');
            $activeSensors .= '- ' . IPS_GetName($sensorID) . "\n";
            $this->WriteAttributeInteger('LastAlert', $sensorID);
            $this->SetValue('ActiveSensors', $activeSensors);
            IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), false);
            $this->SetAlert(true);

    }

    private function deleteActive()
    {
            IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), true);
            $this->SetValue('ActiveSensors','');
    }

    private function startDelay()
    {
        //Display Delay
        $this->SetBuffer('TimeActivated', json_encode(time() + $this->ReadPropertyInteger('ActivateDelay')));

        //Unhide countdown and update it the first time
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), false);
        $this->UpdateDisplay();

        //Start timers for display update and activation
        $this->SetTimerInterval('UpdateDisplay', 1000);
        $this->SetTimerInterval('Delay', $this->ReadPropertyInteger('ActivateDelay') * 1000);
    }

    private function stopDelay()
    {
        $this->SetTimerInterval('Delay', 0);
        $this->SetTimerInterval('UpdateDisplay', 0);
        $this->SetValue('DelayDisplay', '00:00:00');
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), true);
    }
}
