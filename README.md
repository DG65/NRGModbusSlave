# ModbusSlave

IP-Symcon-Bibliothek, die IPS zum **Modbus-TCP-Slave (Server)** macht. Externe Modbus-Master
lesen und schreiben IPS-Variablen über eine frei konfigurierbare Registertabelle.

IP-Symcon selbst bietet von Haus aus nur Modbus-**Master**-Funktionalität – dieses Modul
ergänzt die Gegenrichtung. Typische Einsätze:

- IPS-Messwerte für ein übergeordnetes EMS, SCADA- oder Leitsystem bereitstellen
- Sollwerte von externen Reglern, Wallboxen oder Energiemanagern entgegennehmen
- Geräte emulieren, deren Registerbelegung bekannt ist (z. B. Zähler oder Datenlogger),
  um Systeme anzubinden, die einen bestimmten Modbus-Teilnehmer erwarten
- Direktvermarktungs-Schnittstellen (siehe Vorlage unten)

## Modul: ModbusTCPSlave

### Funktionsweise

Das Modul hängt als Gerät unter einer **Server-Socket**-Instanz (wird beim Anlegen automatisch
erstellt; den Port dort frei einstellen, üblich ist 502). Eingehende Modbus-TCP-Anfragen werden
pro Client gepuffert (TCP-Fragmentierung wird korrekt behandelt), gegen die Registertabelle
beantwortet und gerichtet an den anfragenden Client zurückgesendet. **Mehrere gleichzeitige
Clients auf demselben Port sind möglich** – jede Verbindung erhält einen eigenen Empfangspuffer.

Sollen mehrere getrennte Schnittstellen bedient werden (z. B. verschiedene Ports oder
unterschiedliche Registerbelegungen je Gegenstelle), wird je Schnittstelle eine eigene
Instanz mit eigenem Server Socket angelegt – ein I/O pro Port entspricht der IPS-Architektur.
Die Formular-Aktion **„Weitere Schnittstellen anlegen"** nimmt dabei die Arbeit ab: Eine
Instanz fertig konfigurieren, Portliste eintragen (z. B. „501-505") – für jeden noch freien
Port entsteht eine Kopie samt Server Socket.

**Unterstützte Function Codes:**

| FC | Funktion |
|----|----------|
| 03 | Read Holding Registers |
| 04 | Read Input Registers |
| 06 | Write Single Register |
| 16 | Write Multiple Registers |

**Datentypen:** uint16, int16, uint32, int32, float32, float64.
Die Word-Reihenfolge bei Mehrwort-Typen ist umschaltbar (ABCD/CDAB).

**Registertabelle:** Pro Zeile Adresse (0-basiert), Bereich (Holding/Input), Datentyp,
IPS-Variable, Skalierungsfaktor, optionaler Festwert (wenn keine Variable zugeordnet ist) und
Schreibbar-Flag. Beim Schreiben wird `RequestAction` verwendet, wenn die Zielvariable eine
Aktion besitzt, sonst `SetValue`. Dieselbe Variable darf mehrfach gemappt werden (z. B. float32-
und int32-Darstellung parallel).

### Vorlagen

Vorbereitete Registerbelegungen lassen sich per Button ins Formular laden; gespeichert wird
erst mit „Änderungen übernehmen". Weitere Vorlagen sind nach demselben Muster ergänzbar.

#### Meteocontrol blue'Log RPC (Direktvermarktung)

Emuliert die **Remote-Power-Control-(RPC-)Schnittstelle** des Meteocontrol blue'Log XM/XC
(Datenblatt Stand 05-2020: Unit-ID 10, Port 502, FC 03/16, Word-Order Low vor High):

- **Register 5000** (float32, RW): Wirkleistungs-Sollwertvorgabe 0–125 %
- **Register 5002–5005** (float32, RW): Reserve (wird gespeichert und zurückgeliefert)
- **Register 5006** (float32, RW): Gültigkeitsdauer der Vorgabe in Minuten (1–255, Default 10)
- **Register 5008** (float32, RW): Watchdog – verlängert eine laufende Vorgabe
- **Istwert-Register** 0–14 (float32), 100–114 (int32) und 4000 (P_AV) über die Registertabelle

**Ablauf-Logik gemäß Datenblatt:** Eine geschriebene Sollwertvorgabe gilt für die
Gültigkeitsdauer; jede weitere Vorgabe oder ein Watchdog-Schreiben startet den Ablauf-Timer
neu. Ein Watchdog **nach** Ablauf hält die Vorgabe nicht am Leben – sie muss neu gesetzt
werden. Nach Ablauf fällt der wirksame Sollwert auf den konfigurierbaren
**Rückfall-Sollwert** (Standard 100 %) zurück.

**Variablen bei aktivierter RPC-Schnittstelle:**

| Variable | Bedeutung |
|----------|-----------|
| DV-Sollwertvorgabe | zuletzt vom Master geschriebener Wert (Register 5000) |
| DV-Vorgabe gültig | true solange die Gültigkeitsdauer läuft |
| DV-Vorgabe gültig bis | Zeitstempel des Ablaufs |
| **Wirksamer DV-Sollwert** | Vorgabe solange gültig, sonst Rückfall-Sollwert – hier EMS/Weiterleitung anbinden |
| Gültigkeitsdauer | aktueller Wert von Register 5006 |
| Letzte DV-Vorgabe | Zeitstempel des letzten Schreibens (Sollwert oder Watchdog) |

**Weiterleitung an einen echten blue'Log** (IPS als Zwischenschicht): Ereignis auf
„Wirksamer DV-Sollwert" legen oder das optionale Ereignis-Skript konfigurieren
(`$_IPS['Action']` = `setpoint` | `watchdog` | `expired`, `$_IPS['Setpoint']`, `$_IPS['Valid']`)
und den Wert über eine IPS-ModBus-Master-Instanz in Register 5000 des blue'Log schreiben.

### Einrichtung

1. Modul über die Modulverwaltung installieren (GitHub-URL)
2. Instanz „Modbus TCP Slave" anlegen – der Server Socket wird automatisch erstellt
3. Port am Server Socket einstellen (z. B. 502) und Socket aktivieren
4. Registertabelle füllen (manuell oder per Vorlage), Variablen zuordnen, übernehmen

### Test von der Kommandozeile

```
# Holding-Register lesen (hier: Register 5000 als float32, Unit 10, 0-basiert)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP>

# Wert schreiben (hier: 30 auf Register 5000)
modpoll -m tcp -t4:float -r 5000 -a 10 -0 -1 <IPS-IP> 30
```

### Grenzen

- Coils/Discrete Inputs (FC 01/02/05/15) sind bewusst nicht implementiert
- PHP-Module sind nicht auf Millisekunden-Latenz optimiert; für übliche
  Poll-Intervalle (≥ 500 ms) unkritisch
- Port 502 erfordert je nach System Root-Rechte (alternativ z. B. 1502 verwenden)

## Tests

Der Protokollkern (`libs/ModbusServer.php`) ist IPS-frei und mit der PHP-CLI testbar:

```
php tests/codec_test.php
```
