<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ModbusServer.php';

/**
 * ModbusTCPSlave
 *
 * Macht IP-Symcon zum Modbus-TCP-Slave (Server): externe Modbus-Master können
 * IPS-Variablen über eine frei konfigurierbare Registertabelle lesen und
 * schreiben (FC 03/04/06/16, uint16 bis float64, umschaltbare Word-Reihenfolge).
 * Als I/O dient der IPS Server Socket (wird als Parent automatisch angelegt).
 * Typische Einsätze: IPS-Daten für EMS, SCADA, Wallboxen, Logger oder Regler
 * bereitstellen bzw. Sollwerte von solchen Systemen entgegennehmen.
 *
 * Als optionales Profil ist die Remote-Power-Control-(RPC-)Schnittstelle des
 * Meteocontrol blue'Log XM/XC enthalten (Direktvermarktung): Sollwertvorgabe
 * (Register 5000), Gültigkeitsdauer (5006) und Watchdog (5008) inklusive
 * Ablauf-Logik; Registervorlage per Formular-Button. Protokoll-Referenz:
 * Datenblatt "Remote Power Control (RPC) blue'Log XM/XC", Stand 05-2020.
 * Weitere Vorlagen (z. B. SunSpec-Ausschnitte) sind nach demselben Muster
 * ergänzbar.
 */
class ModbusTCPSlave extends IPSModule
{
    // eigene Modul-GUID (siehe module.json)
    private const MODULE_GUID = '{3F519A7D-1ABC-417D-BC08-8CCEDE0BEEE8}';
    // IPS Server Socket (I/O), wird als Parent benötigt
    private const SERVER_SOCKET_MODULE = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    // Datenpaket "Erweitert (Socket)": Empfang vom Server Socket
    private const RX_DATA_ID = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
    // Datenpaket "Erweitert (Socket)": gerichtetes Senden an einen Client
    private const TX_DATA_ID = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RequireParent(self::SERVER_SOCKET_MODULE);

        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyBoolean('CheckUnitID', true);
        $this->RegisterPropertyBoolean('SwapWords', false);
        $this->RegisterPropertyInteger('UnmappedRead', MBSLVModbusServer::UNMAPPED_ZERO);
        $this->RegisterPropertyString('Registers', '[]');

        // Meteocontrol RPC / Direktvermarktung
        $this->RegisterPropertyBoolean('RPCEnabled', false);
        $this->RegisterPropertyFloat('RPCFallback', 100.0);
        $this->RegisterPropertyFloat('RPCDefaultValidTime', 10.0);
        $this->RegisterPropertyInteger('RPCForwardScript', 0);

        // Zuletzt geschriebener Watchdog-Wert (Register 5008) und die laut
        // Datenblatt ab FW 16.0.4 les-/schreibbaren Reserve-Register 5002-5005
        $this->RegisterAttributeFloat('WatchdogValue', 0.0);
        $this->RegisterAttributeString('ScratchValues', '{}');

        $this->RegisterTimer('Expire', 0, 'MBSLV_CheckExpire($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->ensureProfiles();

        if ($this->ReadPropertyBoolean('RPCEnabled')) {
            $this->RegisterVariableFloat('Setpoint', 'DV-Sollwertvorgabe', 'MBSLV.Percent', 10);
            $this->RegisterVariableBoolean('SetpointValid', 'DV-Vorgabe gültig', '~Switch', 20);
            $this->RegisterVariableInteger('ValidUntil', 'DV-Vorgabe gültig bis', '~UnixTimestamp', 30);
            $this->RegisterVariableFloat('Effective', 'Wirksamer DV-Sollwert', 'MBSLV.Percent', 40);
            $this->RegisterVariableFloat('ValidTime', 'Gültigkeitsdauer', 'MBSLV.Minutes', 50);
            $this->RegisterVariableInteger('LastWrite', 'Letzte DV-Vorgabe', '~UnixTimestamp', 60);

            if ($this->GetValue('ValidTime') < 1) {
                $this->SetValue('ValidTime', $this->ReadPropertyFloat('RPCDefaultValidTime'));
            }

            // Zustand nach Neustart/Übernehmen wiederherstellen
            if ($this->GetValue('SetpointValid')) {
                $remaining = $this->GetValue('ValidUntil') - time();
                if ($remaining <= 0) {
                    $this->CheckExpire();
                } else {
                    $this->SetTimerInterval('Expire', $remaining * 1000);
                }
            } else {
                $this->SetValue('Effective', $this->ReadPropertyFloat('RPCFallback'));
                $this->SetTimerInterval('Expire', 0);
            }
        } else {
            $this->SetTimerInterval('Expire', 0);
        }

        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Empfängt Rohdaten vom Server Socket (Datenpaket "Erweitert (Socket)"),
     * setzt daraus vollständige Modbus-TCP-Frames zusammen und beantwortet sie
     * gerichtet an den jeweiligen Client.
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $ip = (string) ($data->ClientIP ?? '');
        $port = (int) ($data->ClientPort ?? 0);
        $type = (int) ($data->Type ?? 0);
        $key = 'RX_' . $ip . '_' . $port;

        if ($type === 1) { // Verbindung hergestellt
            $this->SetBuffer($key, '');
            $this->SendDebug('Verbindung', sprintf('%s:%d verbunden', $ip, $port), 0);
            return '';
        }
        if ($type === 2) { // Verbindung beendet
            $this->SetBuffer($key, '');
            $this->SendDebug('Verbindung', sprintf('%s:%d getrennt', $ip, $port), 0);
            return '';
        }

        $raw = $this->decodeBuffer((string) ($data->Buffer ?? ''));
        $this->SendDebug('RX ' . $ip . ':' . $port, $raw, 1);

        $buffer = $this->GetBuffer($key) . $raw;
        [$frames, $rest] = MBSLVModbusServer::extractFrames($buffer);
        // Schutz gegen Nicht-Modbus-Datenmüll auf dem Port
        $this->SetBuffer($key, strlen($rest) > 2048 ? '' : $rest);

        if ($frames === []) {
            return '';
        }

        $server = $this->buildServer();
        foreach ($frames as $frame) {
            $response = $server->process($frame);
            if ($response === null) {
                continue;
            }
            $this->SendDebug('TX ' . $ip . ':' . $port, $response, 1);
            $this->SendDataToParent(json_encode([
                'DataID'     => self::TX_DATA_ID,
                'Buffer'     => $this->encodeBuffer($response),
                'ClientIP'   => $ip,
                'ClientPort' => $port,
                'Type'       => 0
            ]));
        }
        return '';
    }

    /**
     * Timer-Callback: prüft den Ablauf der DV-Sollwertvorgabe und schaltet nach
     * Ablauf der Gültigkeitsdauer auf den Rückfall-Sollwert.
     */
    public function CheckExpire(): void
    {
        $this->SetTimerInterval('Expire', 0);
        if (!$this->ReadPropertyBoolean('RPCEnabled') || !$this->GetValue('SetpointValid')) {
            return;
        }
        $remaining = $this->GetValue('ValidUntil') - time();
        if ($remaining > 0) {
            $this->SetTimerInterval('Expire', $remaining * 1000);
            return;
        }
        $fallback = $this->ReadPropertyFloat('RPCFallback');
        $this->SetValue('SetpointValid', false);
        $this->SetValue('Effective', $fallback);
        $this->SendDebug('RPC', sprintf('Sollwertvorgabe abgelaufen - Rückfall auf %.1f %%', $fallback), 0);
        $this->runForwardScript('expired', $fallback);
    }

    /**
     * Formular-Button: lädt die blue'Log-RPC-Registervorlage in die offene
     * Konfiguration (nur UpdateFormField - gespeichert wird erst durch den
     * Nutzer über "Änderungen übernehmen").
     */
    public function LoadRPCProfile(): void
    {
        $effective = 0;
        if ($this->ReadPropertyBoolean('RPCEnabled')) {
            $effective = (int) @$this->GetIDForIdent('Effective');
        }

        $float = function (int $addr, string $name, int $variable = 0, float $fixed = 0.0) {
            return ['Name' => $name, 'Area' => 0, 'Address' => $addr, 'DataType' => 'float32',
                'VariableID' => $variable, 'Factor' => 1.0, 'Fixed' => $fixed, 'Writable' => false];
        };
        $int = function (int $addr, string $name, int $variable = 0, float $fixed = 0.0) {
            return ['Name' => $name, 'Area' => 0, 'Address' => $addr, 'DataType' => 'int32',
                'VariableID' => $variable, 'Factor' => 1.0, 'Fixed' => $fixed, 'Writable' => false];
        };

        $rows = [
            $float(0, 'PPC_P_AC_INV - Summe WR-Wirkleistung (W)'),
            $float(2, 'PPC_P_AC - Ist-Wirkleistung Netzanalysator (W)'),
            $float(4, 'PPC_P_SET_REL - aktuell gültiger Sollwert (%)', $effective),
            $float(6, 'PPC_P_SET_GRIDOP_REL - Sollwert Netzbetreiber (%)', 0, 100.0),
            $float(8, 'PPC_P_SET_RPC_REL - Sollwert Direktvermarkter (%)', $effective),
            $float(10, 'PPC_P_AC_GRIDOP_MAX - max. Leistung Netzbetreiber (W)'),
            $float(12, 'PPC_P_AC_RPC_MAX - max. Leistung Dritte (W)'),
            $float(14, 'PPC_P_SET_MODUS - Regelmodus (5 = RPC)', 0, 5.0),
            $int(100, 'PPC_P_AC_INV - Summe WR-Wirkleistung (W, int32)'),
            $int(102, 'PPC_P_AC - Ist-Wirkleistung Netzanalysator (W, int32)'),
            $int(104, 'PPC_P_SET_REL - aktuell gültiger Sollwert (%, int32)', $effective),
            $int(106, 'PPC_P_SET_GRIDOP_REL - Sollwert Netzbetreiber (%, int32)', 0, 100.0),
            $int(108, 'PPC_P_SET_RPC_REL - Sollwert Direktvermarkter (%, int32)', $effective),
            $int(110, 'PPC_P_AC_GRIDOP_MAX - max. Leistung Netzbetreiber (W, int32)'),
            $int(112, 'PPC_P_AC_RPC_MAX - max. Leistung Dritte (W, int32)'),
            $int(114, 'PPC_P_SET_MODUS - Regelmodus (5 = RPC, int32)', 0, 5.0),
            $float(4000, 'PPC_P_AV - vereinbarte Anschlusswirkleistung (W)')
        ];

        $this->UpdateFormField('Registers', 'values', json_encode($rows));
        $this->UpdateFormField('UnitID', 'value', 10);
        $this->UpdateFormField('CheckUnitID', 'value', true);
        $this->UpdateFormField('SwapWords', 'value', true);
        $this->UpdateFormField('RPCEnabled', 'value', true);

        if ($effective > 0) {
            echo "Vorlage geladen. Bitte die Istwert-Variablen (WR-Leistung, Netzleistung, P_AV) zuordnen und mit 'Änderungen übernehmen' speichern.";
        } else {
            echo "Vorlage geladen. Nach 'Änderungen übernehmen' den Button erneut anklicken, damit die Sollwert-Register (4/8/104/108) automatisch mit der Variable 'Wirksamer DV-Sollwert' verknüpft werden. Istwert-Variablen bitte manuell zuordnen.";
        }
    }

    /**
     * Formular-Button: legt für jeden angegebenen Port eine Kopie dieser
     * Instanz samt Server Socket an (z. B. "502-505" oder "502,503").
     * Ports, auf denen bereits eine ModbusTCPSlave-Instanz lauscht (inklusive
     * dieser), werden übersprungen. Die neuen Instanzen sind vollständige
     * Kopien der GESPEICHERTEN Konfiguration dieser Instanz.
     */
    public function CreateSiblings(string $Ports): void
    {
        $ports = [];
        foreach (explode(',', $Ports) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                for ($p = (int) $m[1]; $p <= (int) $m[2] && count($ports) < 50; $p++) {
                    $ports[] = $p;
                }
            } elseif ($part !== '' && preg_match('/^\d+$/', $part)) {
                $ports[] = (int) $part;
            }
        }
        $ports = array_values(array_unique(array_filter($ports, fn ($p) => $p > 0 && $p <= 65535)));
        if ($ports === []) {
            echo "Keine gültigen Ports angegeben. Beispiele: '502-505' oder '502,503,1502'.";
            return;
        }

        // bereits belegte Ports aller ModbusTCPSlave-Instanzen ermitteln
        $usedPorts = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $instanceID) {
            $socket = IPS_GetInstance($instanceID)['ConnectionID'];
            if ($socket > 0) {
                $usedPorts[(int) IPS_GetProperty($socket, 'Port')] = true;
            }
        }

        $config = json_decode(IPS_GetConfiguration($this->InstanceID), true);
        $baseName = preg_replace('/ \(Port \d+\)$/', '', IPS_GetName($this->InstanceID));
        $location = IPS_GetObject($this->InstanceID)['ParentID'];

        $created = [];
        $skipped = [];
        foreach ($ports as $port) {
            if (isset($usedPorts[$port])) {
                $skipped[] = $port;
                continue;
            }

            $instance = IPS_CreateInstance(self::MODULE_GUID);
            IPS_SetName($instance, $baseName . ' (Port ' . $port . ')');
            IPS_SetParent($instance, $location);
            foreach ($config as $property => $value) {
                IPS_SetProperty($instance, $property, $value);
            }
            IPS_ApplyChanges($instance);

            // RequireParent legt den Server Socket normalerweise automatisch an
            $socket = IPS_GetInstance($instance)['ConnectionID'];
            if ($socket === 0) {
                $socket = IPS_CreateInstance(self::SERVER_SOCKET_MODULE);
                IPS_ConnectInstance($instance, $socket);
            }
            IPS_SetName($socket, 'Server Socket (Modbus TCP Slave Port ' . $port . ')');
            IPS_SetProperty($socket, 'Port', $port);
            IPS_SetProperty($socket, 'Open', true);
            @IPS_ApplyChanges($socket);

            $usedPorts[$port] = true;
            $created[] = $port;
        }

        $message = [];
        if ($created !== []) {
            $message[] = 'Angelegt: Port ' . implode(', ', $created) . '.';
        }
        if ($skipped !== []) {
            $message[] = 'Übersprungen (bereits belegt): Port ' . implode(', ', $skipped) . '.';
        }
        $message[] = 'Hinweis: Kopiert wurde die zuletzt GESPEICHERTE Konfiguration dieser Instanz.';
        echo implode("\n", $message);
    }

    // ---------------------------------------------------------------------
    // interner Teil
    // ---------------------------------------------------------------------

    private function buildServer(): MBSLVModbusServer
    {
        $rows = json_decode($this->ReadPropertyString('Registers'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $normalized = [];
        foreach ($rows as $row) {
            $factor = (float) ($row['Factor'] ?? 1.0);
            $normalized[] = [
                'Area'       => (int) ($row['Area'] ?? MBSLVModbusServer::AREA_HOLDING),
                'Address'    => (int) ($row['Address'] ?? 0),
                'DataType'   => (string) ($row['DataType'] ?? 'uint16'),
                'VariableID' => (int) ($row['VariableID'] ?? 0),
                'Factor'     => $factor == 0.0 ? 1.0 : $factor,
                'Fixed'      => (float) ($row['Fixed'] ?? 0.0),
                'Writable'   => (bool) ($row['Writable'] ?? false)
            ];
        }

        if ($this->ReadPropertyBoolean('RPCEnabled')) {
            foreach ([
                'RPC_SETPOINT'  => 5000,
                'RPC_SCRATCH0'  => 5002,
                'RPC_SCRATCH1'  => 5004,
                'RPC_VALIDTIME' => 5006,
                'RPC_WATCHDOG'  => 5008
            ] as $ident => $address) {
                $normalized[] = [
                    'Area'     => MBSLVModbusServer::AREA_HOLDING,
                    'Address'  => $address,
                    'DataType' => 'float32',
                    'Factor'   => 1.0,
                    'Writable' => true,
                    'Ident'    => $ident
                ];
            }
        }

        return new MBSLVModbusServer(
            $normalized,
            $this->ReadPropertyBoolean('SwapWords'),
            $this->ReadPropertyInteger('UnitID'),
            $this->ReadPropertyBoolean('CheckUnitID'),
            $this->ReadPropertyInteger('UnmappedRead'),
            fn (array $row): float => $this->readRegisterValue($row),
            function (array $row, float $value): void {
                $this->writeRegisterValue($row, $value);
            },
            function (string $topic, string $message): void {
                $this->SendDebug($topic, $message, 0);
            }
        );
    }

    private function readRegisterValue(array $row): float
    {
        if (isset($row['Ident'])) {
            switch ($row['Ident']) {
                case 'RPC_SETPOINT':
                    return (float) $this->GetValue('Setpoint');
                case 'RPC_VALIDTIME':
                    return (float) $this->GetValue('ValidTime');
                case 'RPC_WATCHDOG':
                    return $this->ReadAttributeFloat('WatchdogValue');
                default:
                    $scratch = json_decode($this->ReadAttributeString('ScratchValues'), true);
                    return (float) ($scratch[$row['Ident']] ?? 0.0);
            }
        }

        $variable = $row['VariableID'];
        if ($variable >= 10000 && IPS_VariableExists($variable)) {
            $value = GetValue($variable);
            if (is_bool($value)) {
                $value = $value ? 1.0 : 0.0;
            }
            return (float) $value * $row['Factor'];
        }
        return $row['Fixed'];
    }

    private function writeRegisterValue(array $row, float $value): void
    {
        if (isset($row['Ident'])) {
            $this->rpcWrite($row['Ident'], $value);
            return;
        }

        $variable = $row['VariableID'];
        if ($variable < 10000 || !IPS_VariableExists($variable)) {
            $this->SendDebug('Schreiben', sprintf('Register %d: keine Zielvariable zugeordnet', $row['Address']), 0);
            return;
        }
        $scaled = $value / $row['Factor'];
        $info = IPS_GetVariable($variable);
        switch ($info['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $target = $scaled >= 0.5;
                break;
            case VARIABLETYPE_INTEGER:
                $target = (int) round($scaled);
                break;
            case VARIABLETYPE_STRING:
                $target = (string) $scaled;
                break;
            default:
                $target = $scaled;
        }
        $hasAction = ($info['VariableCustomAction'] > 0) || ($info['VariableAction'] > 0);
        $this->SendDebug('Schreiben', sprintf('Register %d -> Variable #%d = %s (%s)', $row['Address'], $variable, json_encode($target), $hasAction ? 'RequestAction' : 'SetValue'), 0);
        if ($hasAction) {
            @RequestAction($variable, $target);
        } else {
            SetValue($variable, $target);
        }
    }

    private function rpcWrite(string $ident, float $value): void
    {
        $now = time();
        switch ($ident) {
            case 'RPC_SETPOINT':
                $value = max(0.0, min(125.0, $value));
                $this->SendDebug('RPC', sprintf('Sollwertvorgabe %.3f %% empfangen', $value), 0);
                $this->SetValue('Setpoint', $value);
                $this->SetValue('SetpointValid', true);
                $this->SetValue('LastWrite', $now);
                $this->armExpiry($now);
                $this->SetValue('Effective', $value);
                $this->runForwardScript('setpoint', $value);
                break;

            case 'RPC_VALIDTIME':
                $value = max(1.0, min(255.0, $value));
                $this->SendDebug('RPC', sprintf('Gültigkeitsdauer %.1f min empfangen', $value), 0);
                $this->SetValue('ValidTime', $value);
                break;

            case 'RPC_WATCHDOG':
                $this->WriteAttributeFloat('WatchdogValue', $value);
                if ($this->GetValue('SetpointValid')) {
                    $this->SendDebug('RPC', 'Watchdog empfangen - Gültigkeitsdauer neu gestartet', 0);
                    $this->SetValue('LastWrite', $now);
                    $this->armExpiry($now);
                    $this->runForwardScript('watchdog', (float) $this->GetValue('Setpoint'));
                } else {
                    // lt. Datenblatt: Watchdog nach Ablauf hält den Sollwert NICHT am Leben
                    $this->SendDebug('RPC', 'Watchdog empfangen, aber Vorgabe bereits abgelaufen - ignoriert', 0);
                }
                break;

            default: // RPC_SCRATCH0 / RPC_SCRATCH1 (Reserve-Register 5002-5005)
                $scratch = json_decode($this->ReadAttributeString('ScratchValues'), true);
                if (!is_array($scratch)) {
                    $scratch = [];
                }
                $scratch[$ident] = $value;
                $this->WriteAttributeString('ScratchValues', json_encode($scratch));
        }
    }

    private function armExpiry(int $now): void
    {
        $minutes = (float) $this->GetValue('ValidTime');
        if ($minutes < 1) {
            $minutes = $this->ReadPropertyFloat('RPCDefaultValidTime');
        }
        $until = $now + (int) round($minutes * 60);
        $this->SetValue('ValidUntil', $until);
        $this->SetTimerInterval('Expire', ($until - $now) * 1000);
    }

    private function runForwardScript(string $action, float $setpoint): void
    {
        $script = $this->ReadPropertyInteger('RPCForwardScript');
        if ($script < 10000 || !IPS_ScriptExists($script)) {
            return;
        }
        IPS_RunScriptEx($script, [
            'Action'     => $action, // setpoint | watchdog | expired
            'Setpoint'   => $setpoint,
            'Valid'      => $this->GetValue('SetpointValid'),
            'InstanceID' => $this->InstanceID
        ]);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('MBSLV.Percent')) {
            IPS_CreateVariableProfile('MBSLV.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('MBSLV.Percent', 0, 125, 0);
            IPS_SetVariableProfileDigits('MBSLV.Percent', 1);
            IPS_SetVariableProfileText('MBSLV.Percent', '', ' %');
        }
        if (!IPS_VariableProfileExists('MBSLV.Minutes')) {
            IPS_CreateVariableProfile('MBSLV.Minutes', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('MBSLV.Minutes', 1, 255, 0);
            IPS_SetVariableProfileDigits('MBSLV.Minutes', 0);
            IPS_SetVariableProfileText('MBSLV.Minutes', '', ' min');
        }
    }

    /** Binärdaten für den JSON-Datenaustausch kodieren (Latin-1 -> UTF-8) */
    private function encodeBuffer(string $data): string
    {
        return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
    }

    /** Binärdaten aus dem JSON-Datenaustausch dekodieren (UTF-8 -> Latin-1) */
    private function decodeBuffer(string $data): string
    {
        return mb_convert_encoding($data, 'ISO-8859-1', 'UTF-8');
    }
}
