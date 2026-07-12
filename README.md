# ModbusSlave

IP-Symcon-Bibliothek, die IPS zum **Modbus-TCP-Slave (Server)** macht. Externe Modbus-Master
(Direktvermarkter, Energiemanagement-Systeme, Wallboxen, SCADA, …) können IPS-Variablen über
eine frei konfigurierbare Registertabelle lesen und schreiben.

IP-Symcon selbst bietet von Haus aus nur Modbus-**Master**-Funktionalität – dieses Modul
ergänzt die Gegenrichtung.

## Modul: ModbusTCPSlave

### Funktionsweise

Das Modul hängt als Gerät unter einer **Server-Socket**-Instanz (wird beim Anlegen automatisch
erstellt, Standard-Port 502). Eingehende Modbus-TCP-Anfragen werden pro Client gepuffert
(TCP-Fragmentierung wird korrekt behandelt), gegen die Registertabelle beantwortet und gerichtet
an den anfragenden Client zurückgesendet. Mehrere gleichzeitige Clients sind möglich.

**Unterstützte Function Codes:**

| FC | Funktion |
|----|----------|
| 03 | Read Holding Registers |
| 04 | Read Input Registers |
| 06 | Write Single Register |
| 16 | Write Multiple Registers |

**Datentypen:** uint16, int16, uint32, int32, float32, float64.
Die Word-Reihenfolge bei Mehrwort-Typen ist umschaltbar (Low-Word zuerst = blue'Log/RPC-Stil).

**Registertabelle:** Pro Zeile Adresse (0-basiert), Bereich (Holding/Input), Datentyp,
IPS-Variable, Skalierungsfaktor, optionaler Festwert (wenn keine Variable zugeordnet ist) und
Schreibbar-Flag. Beim Schreiben wird `RequestAction` verwendet, wenn die Zielvariable eine
Aktion besitzt, sonst `SetValue`. Dieselbe Variable darf mehrfach gemappt werden (z. B. float32-
und int32-Darstellung parallel).

### Meteocontrol blue'Log RPC (Direktvermarktung)

Optional emuliert das Modul die **Remote-Power-Control-(RPC-)Schnittstelle** des Meteocontrol
blue'Log XM/XC (Datenblatt Stand 05-2020: Unit-ID 10, Port 502, FC 03/16, Word-Order Low vor
High, Fehlwerte int 0x80000000 / float 0x7fc00000):

- **Register 5000** (float32, RW): Wirkleistungs-Sollwertvorgabe 0–125 %
- **Register 5002–5005** (float32, RW): Reserve (wird gespeichert und zurückgeliefert)
- **Register 5006** (float32, RW): Gültigkeitsdauer der Vorgabe in Minuten (1–255, Default 10)
- **Register 5008** (float32, RW): Watchdog – verlängert eine laufende Vorgabe
- **Istwert-Register** 0–14 (float32), 100–114 (int32) und 4000 (P_AV) über die Registertabelle;
  eine passende Vorlage lässt sich per Button im Formular laden

**Ablauf-Logik gemäß Datenblatt:** Eine geschriebene Sollwertvorgabe gilt für die
Gültigkeitsdauer; jede weitere Vorgabe oder ein Watchdog-Schreiben startet den Ablauf-Timer
neu. Ein Watchdog **nach** Ablauf hält die Vorgabe nicht am Leben – sie muss neu gesetzt
werden. Nach Ablauf fällt der wirksame Sollwert auf den konfigurierbaren
**Rückfall-Sollwert** (Standard 100 %) zurück.

**Variablen bei aktivierter RPC-Schnittstelle:**

| Variable | Bedeutung |
|----------|-----------|
| DV-Sollwertvorgabe | zuletzt vom Direktvermarkter geschriebener Wert (Register 5000) |
| DV-Vorgabe gültig | true solange die Gültigkeitsdauer läuft |
| DV-Vorgabe gültig bis | Zeitstempel des Ablaufs |
| **Wirksamer DV-Sollwert** | Vorgabe solange gültig, sonst Rückfall-Sollwert – hier EMS/Weiterleitung anbinden |
| Gültigkeitsdauer | aktueller Wert von Register 5006 |
| Letzte DV-Vorgabe | Zeitstempel des letzten Schreibens (Sollwert oder Watchdog) |

**Weiterleitung an einen echten blue'Log** (IPS als Zwischenschicht): Ereignis auf
"Wirksamer DV-Sollwert" legen oder das optionale Ereignis-Skript konfigurieren
(`$_IPS['Action']` = `setpoint` | `watchdog` | `expired`, `$_IPS['Setpoint']`, `$_IPS['Valid']`)
und den Wert über eine IPS-ModBus-Master-Instanz in Register 5000 des blue'Log schreiben.

### Einrichtung

1. Modul über die Modulverwaltung installieren (GitHub-URL)
2. Instanz "Modbus TCP Slave" anlegen – der Server Socket wird automatisch erstellt
3. Port am Server Socket prüfen (Standard 502) und Socket aktivieren
4. Für Direktvermarktung: RPC-Schnittstelle aktivieren, Registervorlage laden,
   Istwert-Variablen zuordnen, übernehmen

### Test von der Kommandozeile

```
# Sollwert lesen (Register 5000, Unit 10, 0-basierte Adressierung)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP>

# 30 % Sollwertvorgabe schreiben (entspricht dem modpoll-Beispiel im Datenblatt)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP> 30
```

### Grenzen

- Coils/Discrete Inputs (FC 01/02/05/15) sind bewusst nicht implementiert
- PHP-Module sind nicht auf Millisekunden-Latenz optimiert; für übliche
  Poll-Intervalle (≥ 500 ms, blue'Log-Spezifikation: 1000 ms Delay) unkritisch
- Port 502 erfordert je nach System Root-Rechte (alternativ z. B. 1502 verwenden)

## Tests

Der Protokollkern (`libs/ModbusServer.php`) ist IPS-frei und mit der PHP-CLI testbar:

```
php tests/codec_test.php
```
