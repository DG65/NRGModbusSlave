# Changelog

## 1.0.1 (2026-07-12)

- Texte und Standardwerte neutralisiert: das Modul präsentiert sich als generischer Modbus-TCP-Slave, die blue'Log-RPC-Emulation ist eine optionale Vorlage (Defaults jetzt Unit-ID 1 und Word-Order ABCD; die RPC-Vorlage setzt weiterhin Unit-ID 10 und CDAB)

## 1.0.0 (2026-07-12)

- Erstversion: generisches Modbus-TCP-Slave-Modul (FC 03/04/06/16, uint16/int16/uint32/int32/float32/float64, umschaltbare Word-Reihenfolge, frei konfigurierbare Registertabelle mit Variablen-Mapping, Faktor und Festwerten)
- Meteocontrol blue'Log XM/XC RPC-Emulation für die Direktvermarktung: Register 5000/5002–5005/5006/5008 mit Gültigkeits- und Watchdog-Logik, Rückfall-Sollwert, Registervorlage für die Istwert-Register per Formular-Button
- IPS-freier, CLI-testbarer Protokollkern (tests/codec_test.php)
