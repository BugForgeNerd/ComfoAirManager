<?php
/**
 * NEUSTART des Moduls: MC_ReloadModule(59139, "ComfoAirManager");
 * sudo /etc/init.d/symcon start
 * sudo /etc/init.d/symcon stop
 * sudo /etc/init.d/symcon restart
 *
 * ToDo:
 * - Debugmodus in profile wieder ausschalten, da sonst immer überschrieben wird. überarbeitet werden muss auch RegisterModuleVariables()
 * - Gateway auf Vorhandensein überprüfen -> $this->SendDataToParent(json_encode
 * - 30.12.2025, 19:08:06 | TimerPool            | ComfoAirManager (PendingTimer): Warning: Socket ist nicht verbunden in /var/lib/symcon/modules/ComfoAirManager/ComfoAirManagerCS/module.php on line 623
 * - 
 * - 
 * - Buffer-Handling optimieren. Aktuell: String-Puffer + strpos → alles in ProcessRxBuffer(). Neu: Array/Queue-Puffer + Chunk-Parser, der ACKs und Frames sauber 
 *     trennt. Vorteil: stabiler, weniger Risiko von Frame-Verschiebungen oder Stuffing-Fehlern.
 * - ACK-/Retry-Logik verbessern. Aktuell: einfacher Timer prüft alle PendingRequests. Neu: Statusobjekt pro Request (ackReceived, responseReceived, retryCount), 
 *     optional Exponential Backoff. Vorteil: weniger unnötige Resends, bessere Fehlerdiagnose.
 * - Dispatch / Datenmapping. Aktuell: Werte direkt setzen, Float = /1. Neu: Skalierungen / Lookup-Tabellen für Statuswerte, robustere Byte-Mappings. Vorteil: 
 *     korrekte physikalische Werte, leichter erweiterbar.
 * - Fehlerfälle / Robustheit. Buffer nur fehlerhaften Frame entfernen, Rest behalten. Vorteil: Modul stürzt nicht bei fehlerhaften Bytes ab, stabile Kommunikation. 
*/

/**
 * Klasse ComfoAirManager
 * 
 * - IPSModule zur Anbindung und Steuerung von ComfoAir Lüftungsanlagen.
 * - Verwaltung von Lüftungsstufen, Temperaturen, Störungen, Betriebsstunden und Bypass/Frostschutzfunktionen.
 * - Enthält AutoRead-Funktionalität mit Queue/Pending-Logik.
 * - Unterstützt Hitzesteuerung basierend auf Innen-/Außentemperatur und Komforttemperatur.
 * - Definiert zentrale Konstanten für Protokoll (START, END, ACK) und HeatControl Intervall.
 * - Befehle in $Commands strukturieren alle auslesbaren und schreibbaren Variablen inklusive Metadaten (Typ, Profil, Postprocessing, Default, Action).
 * 
 * Konstante HEAT_CONTROL_INTERVAL:
 *   int → Intervall für Hitzesteuerung in Millisekunden (15 Minuten)
 * 
 * Protokoll-Konstanten:
 *   string START → Startbyte für Kommunikationspaket
 *   string END   → Endbyte für Kommunikationspaket
 *   string ACK   → ACK Byte für Bestätigung
 * 
 * Geschütztes Array $Commands:
 *   array → definiert alle Command-Gruppen inkl.:
 *     - key: eindeutiger Schlüssel
 *     - command_request: Command-ID für Anforderung
 *     - command_response: Command-ID für Antwort (falls vorhanden)
 *     - description: Beschreibung der Funktion
 *     - command_type: read/write
 *     - pollable: bool, ob regelmäßig abfragbar
 *     - data: Variableninformationen (name, type, variable, action, default, profile, postprocessing)
 */
declare(strict_types=1);
class ComfoAirManager extends IPSModuleStrict
{
	// Intervall Hitzesteuerung
	private const HEAT_CONTROL_INTERVAL = 15 * 60 * 1000; // 15 Minuten
	
    // Protokollkonstanten
    private const START = "\x07\xF0";
    private const END   = "\x07\x0F";
    private const ACK   = "\x07\xF3";
	
	protected array $Commands = [
		'Ventilationsstufe' => [
			'key' => 'ventilationsstufe',
			'command_request'  => 0x00CD,
			'command_response' => 0x00CE,
			'description'      => 'Ventilationsstufe abrufen, sowie die Voreinstellungsdaten zu den Stufen',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1  => ['name' => 'VS - Abluft abwesend Stufe 0', 'type' => 'percent', 'variable' => 'vsAbluftAbwesend', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				2  => ['name' => 'VS - Abluft niedrig Stufe 1', 'type' => 'percent', 'variable' => 'vsAbluftNiedrig', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				3  => ['name' => 'VS - Abluft mittel Stufe 2', 'type' => 'percent', 'variable' => 'vsAbluftMittel', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				4  => ['name' => 'VS - Zuluft abwesend Stufe 0', 'type' => 'percent', 'variable' => 'vsZuluftAbwesend', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				5  => ['name' => 'VS - Zuluft niedrig Stufe 1', 'type' => 'percent', 'variable' => 'vsZuluftNiedrig', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				6  => ['name' => 'VS - Zuluft mittel Stufe 2', 'type' => 'percent', 'variable' => 'vsZuluftMittel', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				7  => ['name' => 'VS - Abluft aktuell', 'type' => 'percent', 'variable' => 'vsAbluftAktuell', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				8  => ['name' => 'VS - Zuluft aktuell', 'type' => 'percent', 'variable' => 'vsZuluftAktuell', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				9  => ['name' => 'VS - Aktuelle Stufe', 'type' => 'integer', 'variable' => 'vsAktuelleStufe', 'action' => true, 'default'  => true, 'profile'  => 'Comfo_LueftungsStufe'], 
				// via 0x0099 beschreibbar bzw. die Daten werden unter anderen hierhin zurück geliefert.	
				// bei mir sind die Daten:
				// Wert/Value = 0x00 ist "Auto - geht aber nur mit Fernbedienung, daher in diesem Modul uninteressant"
				// Wert/Value = 0x01 ist "Aus bzw. annähernd Ventilatoren bei 0"
				// Wert/Value = 0x02 ist "Stufe 1 niedrig"
				// Wert/Value = 0x03 ist "Stufe 2 mittel"
				// Wert/Value = 0x04 ist "Stufe 3 hoch"
				10 => ['name' => 'VS - Zuluft Ventilator aktiv', 'type' => 'boolean', 'variable' => 'vsZuluftAktiv', 'default'  => false],
				11 => ['name' => 'VS - Abluft hoch Stufe 3', 'type' => 'percent', 'variable' => 'vsAbluftHoch', 'default'  => false, 'profile'  => 'Comfo_Percent'],
				12 => ['name' => 'VS - Zuluft hoch Stufe 3', 'type' => 'percent', 'variable' => 'vsZuluftHoch', 'default'  => false, 'profile'  => 'Comfo_Percent'],
			]
		],
		'SetVentilationLevel' => [
			'key' => 'setventilationlevel',
			'command_request'  => 0x0099,
			'command_response' => null, // nur ACK wird empfangen
			'description'      => 'Ventilationsstufe setzen',
			'command_type'     => 'write',
			'data'             => [] // keine Variablen im Tree
		],
		'SetKomforttemperatur' => [
			'key' => 'setkomforttemperatur',
			'command_request'  => 0x00D3,
			'command_response' => null, // nur ACK wird empfangen
			'description'      => 'Komforttemperatur setzen',
			'command_type'     => 'write',
			'data'             => [] // keine Variablen im Tree
		],
		'Temperaturen' => [
			'key' => 'temperaturen',
			'command_request'  => 0x00D1,
			'command_response' => 0x00D2,
			'description'      => 'Temperaturen abrufen',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1 => ['name' => 'TE - Komfort Temperatur', 'type' => 'float', 'variable' => 'teKomfortTemperatur', 'action' => true, 'default'  => true, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'], // via 0x00D3 beschreibbar bzw. die Daten werden unter anderen hierhin zurück geliefert.
				2 => ['name' => 'TE - Außenluft T1', 'type' => 'float', 'variable' => 'teT1_Aussenluft', 'default'  => true, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				3 => ['name' => 'TE - Zuluft T2', 'type' => 'float', 'variable' => 'teT2_Zuluft', 'default'  => true, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				4 => ['name' => 'TE - Abluft T3', 'type' => 'float', 'variable' => 'teT3_Abluft', 'default'  => true, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				5 => ['name' => 'TE - Fortluft T4', 'type' => 'float', 'variable' => 'teT4_Fortluft', 'default'  => true, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				7 => ['name' => 'TE - EWT Temperatur', 'type' => 'float', 'variable' => 'teEWT', 'default'  => false, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				8 => ['name' => 'TE - Nachheizung Temperatur', 'type' => 'float', 'variable' => 'teNachheizung', 'default'  => false, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
				9 => ['name' => 'TE - Küchenhaube Temperatur', 'type' => 'float', 'variable' => 'teKuechenhaube', 'default'  => false, 'postprocessing' => 'temp_half_minus_20', 'profile'  => 'Comfo_Temperatur'],
			]
		],
		'Störungen' => [
			'key' => 'stoerungen',
			'command_request'  => 0x00D9,
			'command_response' => 0x00DA,
			'description'      => 'Störungen und Filterstatus abrufen',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1 => ['name' => 'ST - Aktueller Fehler A', 'type' => 'integer', 'variable' => 'stAktuellerFehlerA', 'default'  => false],
				2 => ['name' => 'ST - Aktueller Fehler E', 'type' => 'integer', 'variable' => 'stAktuellerFehlerE', 'default'  => false],
				9 => ['name' => 'ST - Filterstatus', 'type' => 'boolean', 'variable' => 'stFilterOk', 'default'  => true, 'profile'  => 'Comfo_Filterstatus'],
				14 => ['name' => 'ST - Aktueller Fehler A high', 'type' => 'integer', 'variable' => 'stAktuellerFehlerAhigh', 'default'  => false],
			]
		],
		'Bypass' => [
			'key' => 'bypass',
			'command_request'  => 0x00DF,
			'command_response' => 0x00E0,
			'description'      => 'Status Bypassregelung',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				4 => ['name' => 'BY - Bypass Stufe', 'type' => 'integer', 'variable' => 'byBypassStufe', 'default'  => false, 'profile'  => 'Comfo_BypassStufe'],
				7 => ['name' => 'BY - Sommermodus', 'type' => 'boolean', 'variable' => 'bySommermodus', 'default'  => false, 'profile'  => 'Comfo_Sommermodus'],
			]
		],
		'Ventilationsstatus' => [
			'key' => 'ventilationsstatus',
			'command_request'  => 0x000B,
			'command_response' => 0x000C,
			'description'      => 'Ventilationsstatus abrufen',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1 => [
					'name' => 'VST - Zuluft', 
					'type' => 'integer', 
					'variable' => 'vstZuluft', 
					'default'  => false, 
					'profile'  => 'Comfo_Percent'],
				2 => [
					'name' => 'VST - Abluft', 
					'type' => 'integer', 
					'variable' => 'vstAbluft', 
					'default'  => false, 
					'profile'  => 'Comfo_Percent'],
				3 => [
					'name' => 'VST - Drehzahl Zuluft Ventilator',
					'type' => 'integer',
					'bytes' => 2,              // Anzahl der Bytes
					'variable' => 'vstDrehzahlZuluftVent',
					'postprocessing' => 'rpm_inverse_1875000',
					'default'  => false,
					'profile'  => 'Comfo_Drehzahl'
				],
				5 => [
					'name' => 'VST - Drehzahl Abluft Ventilator',
					'type' => 'integer',
					'bytes' => 2,              // Anzahl der Bytes
					'variable' => 'vstDrehzahlAbluftVent',
					'postprocessing' => 'rpm_inverse_1875000',
					'default'  => false,
					'profile'  => 'Comfo_Drehzahl'
				],
			]
		],
		'Betriebsstunden' => [
			'key' => 'betriebsstunden',
			'command_request'  => 0x00DD,
			'command_response' => 0x00DE,
			'description'      => 'Betriebsstunden der verschieden Stufen und Einheiten',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1 => [
					'name' => 'BS - Betriebsstunden abwesend',
					'type' => 'integer',
					'bytes' => 3,              // Anzahl der Bytes
					'variable' => 'bsBetriebsstundenAbwesend',
					'default'  => false
				],
				4 => [
					'name' => 'BS - Betriebsstunden niedrig / Stufe 1',
					'type' => 'integer',
					'bytes' => 3,
					'variable' => 'bsBetriebsstundenNiedrig',
					'default'  => false
				],
				7 => [
					'name' => 'BS - Betriebsstunden mittel / Stufe 2',
					'type' => 'integer',
					'bytes' => 3,
					'variable' => 'bsBetriebsstundenMittel',
					'default'  => false
				],
				10 => [
					'name' => 'BS - Betriebsstunden Frostschutz',
					'type' => 'integer',
					'bytes' => 2,
					'variable' => 'bsBetriebsstundenFrostschutz',
					'default'  => false
				],
				12 => [
					'name' => 'BS - Betriebsstunden Vorheizung',
					'type' => 'integer',
					'bytes' => 2,
					'variable' => 'bsBetriebsstundenVorheizung',
					'default'  => false
				],
				14 => [
					'name' => 'BS - Betriebsstunden Bypass offen',
					'type' => 'integer',
					'bytes' => 2,
					'variable' => 'bsBetriebsstundenBypass',
					'default'  => false
				],
				16 => [
					'name' => 'BS - Betriebsstunden Filter',
					'type' => 'integer',
					'bytes' => 2,
					'variable' => 'bsBetriebsstundenFilter',
					'default'  => false
				],
				18 => [
					'name' => 'BS - Betriebsstunden hoch / Stufe 3',
					'type' => 'integer',
					'bytes' => 3,
					'variable' => 'bsBetriebsstundenHoch',
					'default'  => false
				],
			]
		],
		'StatusVorheizung' => [
			'key' => 'vorheizung',
			'command_request'  => 0x00E1,
			'command_response' => 0x00E2,
			'description'      => 'Status der Vorheizung und Frostschutzfunktionen',
			'command_type'     => 'read',
			'pollable'         => true,
			'data'             => [
				1 => [
					'name'     => 'VH - Status Klappe',
					'type'     => 'integer',   // oder 'enum' / 'boolean' je nach Verarbeitung
					'variable' => 'statusKlappe',
					'bytes'    => 1,
					'default'  => false
				],
				2 => [
					'name'     => 'VH - Frostschutz aktiv',
					'type'     => 'boolean',
					'variable' => 'frostschutzAktiv',
					'bytes'    => 1,
					'default'  => false
				],
				3 => [
					'name'     => 'VH - Vorheizung aktiv',
					'type'     => 'boolean',
					'variable' => 'vorheizungAktiv',
					'bytes'    => 1,
					'default'  => false
				],
				4 => [
					'name'     => 'VH - Frostminuten',
					'type'     => 'integer',
					'variable' => 'frostminuten',
					'bytes'    => 2,
					'default'  => false
				],
				6 => [
					'name'     => 'VH - Frostsicherheit',
					'type'     => 'integer',
					'variable' => 'frostsicherheit',
					'bytes'    => 1,
					'default'  => false
				],
			]
		],
	];

	// Benötige eine neue Instanz und bevorzuge einen Serial Port bei der Erstellung
	public function GetCompatibleParents(): string
	{
		return '{"type": "require", "moduleIDs": ["{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}", "{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}"]}';
	}

	/**
	 * Initialisiert die Modulinstanz.
	 *
	 * Legt die grundlegende Modulstruktur an, erzwingt die benötigte Parent-Instanz,
	 * registriert Profile, Timer, Properties, Variablen, Attribute sowie interne Puffer.
	 * Zusätzlich werden alle Konfigurationen für Pending-Requests, Kommando-Queue,
	 * AutoRead-Mechanik und Hitzesteuerung vorbereitet.
	 *
	 * @return void
	 */
    public function Create(): void
    {
        parent::Create();

        //$this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
		
		// Profile prüfen / anlegen
		$this->ProfilCheck();
		
		// Timer für Pending-Requests
		$this->RegisterTimer("PendingTimer", 0, 'CAMCS_CheckPendingRequests($_IPS["TARGET"]);');

        // Empfangspuffer
        $this->SetBuffer('RX', '');
        //$this->SetBuffer('WaitingForAck', '0');
        //$this->SetBuffer('RetryCount', '0');
		
		// Pending Requests für Retry-Logik
		$this->SetBuffer('PendingRequests', json_encode([]));
		
		// Zentrale Command-Queue - Abarbeitung der Kommandos über Warteschlange da verschachtelte Anfragen Probleme machen
		$this->SetBuffer('CommandQueue', json_encode([]));
		
		// Attribute: Timeout in Sekunden und MaxRetry
		// ist die maximale Wartezeit (in Sekunden), die das Modul nach dem Senden eines Kommandos auf eine gültige Antwort wartet, bevor ein Retry ausgelöst wird.
		$this->RegisterAttributeInteger('RequestTimeout', 5); // 5s default
		$this->RegisterAttributeInteger('MaxRetry', 3);      // max 3 Versuche mit je RequestTimeout
		// zusätzliche Wartezeit auf Read-Daten nach ACK (in Sekunden)
		$this->RegisterAttributeInteger('ReadDataTimeout', 10);
		
		// Zentrale Poll-Konfiguration
		$this->RegisterAttributeString('ReadGroupsConfig', json_encode([]));
		// Globaler Schalter
		$this->RegisterPropertyBoolean('AutoReadEnabled', true);
		
		// Hitzesteuerung – Properties, Variablen und Intervall
		$this->RegisterTimer('HeatControlTimer',0,'CAMCS_CheckHeatControl($_IPS["TARGET"]);');  // alle 15 Minuten
		$this->RegisterPropertyInteger('InsideTempVarID', 0);
		$this->RegisterPropertyInteger('OutsideTempVarID', 0);
		$this->RegisterVariableBoolean('Hitzesteuerung','Hitzesteuerung','~Switch');
		$this->EnableAction('Hitzesteuerung');
		$this->RegisterVariableBoolean('LueftungAusWegenHitze','Lüftung aus wegen Hitze','~Alert');
		// gespeicherte Lüftungsstufe vor Hitzesperre
		$this->RegisterAttributeInteger('HeatControlPrevStage', -1);	// -1 = ungültig / nicht gesetzt
		// Wochenplan aktiv vor Hitzesperre?
		$this->RegisterAttributeBoolean('HeatControlPrevScheduleActive', false);
		
		// Scheduler-Timer (NICHT PendingTimer!)
		$this->RegisterTimer('AutoReadScheduler', 0, 'CAMCS_AutoRead($_IPS["TARGET"]);');
		
		// -------------------------------------------------------------
		// AutoRead Properties
		// -------------------------------------------------------------
		foreach ($this->Commands as $groupName => $cmd) {
			if (($cmd['command_type'] ?? '') !== 'read') {
				continue;
			}

			$key = $cmd['key']; // <<< ÄNDERUNG: technischer Key

			// Intervall
			$this->RegisterPropertyInteger("AutoReadInterval_{$key}", 0); // <<< ÄNDERUNG

			foreach ($cmd['data'] as $info) {
				$ident = $info['variable'];
				$defaultValue = $info['default'] ?? false;

				$this->RegisterPropertyBoolean(
					"AutoRead_{$key}_{$ident}", // <<< ÄNDERUNG
					$defaultValue
				);
			}
		}
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

	/**
	 * Übernimmt Konfigurationsänderungen und synchronisiert den Modulzustand.
	 *
	 * Liest alle Properties ein, erstellt daraus die interne AutoRead-Konfiguration,
	 * legt benötigte Modulvariablen an bzw. entfernt sie, aktualisiert Scheduler und
	 * Timer und aktiviert bzw. deaktiviert Funktionen wie AutoRead und Hitzesteuerung
	 * abhängig von der aktuellen Konfiguration.
	 *
	 * @return void
	 */
	public function ApplyChanges(): void
	{
		parent::ApplyChanges();

		// Profile bereitstellen
		$this->ProfilCheck();
		
		// --------------------------------------------
		// 1) PendingTimer immer AUS initialisieren
		// --------------------------------------------
		$this->SetTimerInterval('PendingTimer', 0);

		// --------------------------------------------
		// 2) AutoReadConfig aus Properties übernehmen
		// --------------------------------------------
		$config = [];
		foreach ($this->Commands as $groupName => $cmd) {
			if (($cmd['command_type'] ?? '') !== 'read') {
				continue;
			}

			$key = $cmd['key']; // <<< ÄNDERUNG

			$interval = $this->ReadPropertyInteger(
				"AutoReadInterval_{$key}" // <<< ÄNDERUNG
			);

			$config[$groupName] = [
				'enabled'   => true,
				'interval'  => $interval,
				'lastRun'   => 0,
				'variables' => []
			];

			foreach ($cmd['data'] as $info) {
				$ident = $info['variable'];
				$isDefault = $info['default'] ?? false;

				$config[$groupName]['variables'][$ident] =
					$this->ReadPropertyBoolean(
						"AutoRead_{$key}_{$ident}" // <<< ÄNDERUNG
					) || $isDefault;
			}
		}

		$this->WriteAttributeString('ReadGroupsConfig', json_encode($config));

		// --------------------------------------------
		// 3) Modul-Variablen anlegen / entfernen
		// --------------------------------------------
		$this->RegisterModuleVariables($config);

		// --------------------------------------------
		// 4) AutoRead-Scheduler aktualisieren
		// --------------------------------------------
		$this->UpdateAutoReadScheduler();
		
		// Profile bereitstellen
		$this->ProfilCheck();
		
		$this->SendDebug(__FUNCTION__, "ApplyChanges abgeschlossen", 0);
		
		// Dinge für Hitzesteuerung
		// Timer für Hitzesteuerung immer laufen lassen
		//$this->SetTimerInterval('HeatControlTimer', 5 * 60 * 1000);
		$insideVar  = $this->ReadPropertyInteger('InsideTempVarID');
		$outsideVar = $this->ReadPropertyInteger('OutsideTempVarID');
		$this->SendDebug('HeatControl', 'Value insideVar: ' . $insideVar, 0);
		$this->SendDebug('HeatControl', 'Value outsideVar: ' . $outsideVar, 0);

		// 0 = Root-Objekt, 1 = Ungültige Objekt bzw. nicht ausgewählt. Der < 10000 Check ist gut, da die gültigen IDs bei uns immer mit 10000 anfangen.
		// oder IP_VariableExists prüfen
		$enabled = $this->GetValue('Hitzesteuerung');
		if ($enabled && $insideVar >= 10000 && $outsideVar >= 10000) {
			$this->SetTimerInterval('HeatControlTimer', self::HEAT_CONTROL_INTERVAL);
			$this->SendDebug('HeatControl', 'Timer aktiv', 0);
		} else {
			// Sensoren fehlen → Timer aus
			$this->SetTimerInterval('HeatControlTimer', 0);
			$this->SendDebug('HeatControl', 'Timer deaktiviert (Sensoren fehlen)', 0);
		}

		$this->CreateWochenplan();
	}

	/**
	 * Zentrale Hilfsfunktion für Debug-Ausgaben.
	 *
	 * Kapselt den internen Debug-Aufruf und stellt eine einheitliche Schnittstelle
	 * für Debug-Meldungen innerhalb des Moduls bereit.
	 *
	 * @param string $title   Titel bzw. Kategorie der Debug-Ausgabe
	 * @param string $data    Debug-Daten oder Meldung
	 * @param int    $format  Ausgabeformat (z. B. Text oder Hex), Symcon-konform
	 * @return void
	 */
	private function Debug(string $title, string $data, int $format = 0): void
	{
		$this->SendDebug($title, $data, $format);
	}

	/**
	 * Löst über das Konfigurationsformular einen Testbefehl aus.
	 *
	 * Sendet ein definiertes Testkommando an das angebundene Gerät, um die
	 * Kommunikation und grundlegende Funktion des Moduls zu überprüfen.
	 *
	 * @return void
	 */
    public function SendTestCommand(): void
    {
        // Kommando 0x0069 – Gerätetyp abfragen
        //$this->SendCommand(0x00D1, []);
		$this->SendCommand(0x00D1, []);
    }

	/**
	 * Sendet ein Kommando an das Gerät.
	 *
	 * Erstellt einen vollständigen Kommunikationsrahmen inklusive Start-/Endekennung,
	 * Datenlänge, Checksumme und Byte-Stuffing, versendet diesen roh an das Gateway
	 * und legt bei Bedarf einen Pending-Request für die Retry- und Timeout-Logik an.
	 *
	 * @param int   $command  Kommando-ID (16 Bit)
	 * @param array $data     Nutzdaten als Byte-Array
	 * @return void
	 */
	private function SendCommand(int $command, array $data): void
	{
		$this->Debug('SEND CMD', sprintf('0x%04X (%d Datenbytes)', $command, count($data)), 0);

		$frame = '';

		// Kommando (2 Byte)
		$cmdHi = ($command >> 8) & 0xFF;
		$cmdLo = $command & 0xFF;

		// Daten vorbereiten (unstuffed)
		$length = count($data);

		// Checksumme vorbereiten
		$checksum = $cmdHi + $cmdLo + $length + array_sum($data) + 173;
		$checksum &= 0xFF;

		// Daten mit Stuffing
		$dataStuffed = '';
		foreach ($data as $byte) {
			$dataStuffed .= chr($byte);
			if ($byte === 0x07) {
				$dataStuffed .= chr(0x07);
			}
		}

		$frame =
			self::START .
			chr($cmdHi) .
			chr($cmdLo) .
			chr($length) .
			$dataStuffed .
			chr($checksum) .
			self::END;

		//$this->SetBuffer('WaitingForAck', '1');
		//$this->SetBuffer('RetryCount', '0');

		$this->SendRaw($frame);
		//IPS_LogMessage('ComfoAir SEND', bin2hex($frame));

		// PendingRequest speichern (nur neu, nicht bei Retry überschreiben)
		$pending = $this->GetBuffer('PendingRequests');
		$pendingArr = $pending ? json_decode($pending, true) : [];

		if (!isset($pendingArr[$command])) {

			// Command-Typ (read / write) ermitteln
			$cmdType = 'unknown';
			foreach ($this->Commands as $cmdInfo) {
				if ($cmdInfo['command_request'] === $command) {
					$cmdType = $cmdInfo['command_type'];
					break;
				}
			}

			$pendingArr[$command] = [
				'timestamp'   => time(),
				'retryCount'  => 0,
				'data'        => $data,
				'type'        => $cmdType,
				'ackReceived' => false // ACK-Status (nur relevant für read)
			];

			$this->Debug(
				'Pending',
				sprintf('Neuer PendingRequest 0x%04X (%s)', $command, $cmdType),
				0
			);

			$this->SetBuffer('PendingRequests', json_encode($pendingArr));
			
			// PendingTimer aktivieren, da mindestens ein PendingRequest existiert
			$this->SetTimerInterval('PendingTimer', 1000);
			$this->Debug('Timer','PendingTimer aktiviert (PendingRequest angelegt)', 0);
		}
	}

	/**
	 * Sendet binäre Rohdaten an die übergeordnete I/O-Instanz.
	 *
	 * Übergibt die bereits vollständig aufgebauten Kommunikationsdaten direkt
	 * an das Parent-Gateway (z. B. Serial Port oder Client Socket).
	 *
	 * @param string $binary  Binärer Datenstrom zur Übertragung
	 * @return void
	 */
	private function SendRaw(string $binary): void
	{
		$this->Debug('TX RAW', $binary, 1);

		$this->SendDataToParent(json_encode([
			'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
			//'Buffer' => utf8_encode($binary)
			'Buffer' => bin2hex($binary)
		]));
	}

	/**
	 * Empfängt eingehende Rohdaten vom Parent-Gateway.
	 *
	 * Nimmt Datenpakete aus der I/O-Instanz entgegen, führt sie im internen
	 * Empfangspuffer zusammen und stößt die weitere Verarbeitung der
	 * empfangenen Daten an.
	 *
	 * @param string $JSONString  JSON-kodierte Nutzdaten vom Parent-Gateway
	 * @return string
	 */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString);
        //$chunk = utf8_decode($data->Buffer);
		$chunk = hex2bin($data->Buffer);
		
		$this->Debug('RX RAW', $chunk, 1);

        $buffer = $this->GetBuffer('RX') . $chunk;
        $this->SetBuffer('RX', $buffer);

        $this->ProcessRxBuffer();
		
		return '';
    }

	/**
	 * Verarbeitet den internen Empfangspuffer.
	 *
	 * Analysiert eingehende Daten auf ACK-Sequenzen und vollständige Frames,
	 * aktualisiert Pending-Requests entsprechend des Kommando-Typs und leitet
	 * erkannte Datenframes zur weiteren Verarbeitung weiter.
	 *
	 * @return void
	 */
	private function ProcessRxBuffer(): void
	{
		$buffer = $this->GetBuffer('RX');

		while (strlen($buffer) >= 2) {

			/************************************************************
			 * 1) ACK erkennen
			 ************************************************************/
			$ackPos = strpos($buffer, self::ACK);
			if ($ackPos !== false) {

				$this->Debug('RX', 'ACK empfangen', 0);

				//$this->SetBuffer('WaitingForAck', '0');
				//$this->SetBuffer('RetryCount', '0');

				$pending = $this->GetBuffer('PendingRequests');
				$pendingArr = $pending ? json_decode($pending, true) : [];

				/********************************************************
				 * HIER passiert die entscheidende Logik:
				 *
				 * - WRITE:
				 *   ACK beendet den Request sofort
				 *
				 * - READ:
				 *   ACK markiert nur "ackReceived"
				 *   → Start der ReadDataTimeout-Phase
				 ********************************************************/
				foreach ($pendingArr as $cmd => &$info) {

					// WRITE-PendingRequests sofort abschließen
					if (($info['type'] ?? '') === 'write') {

						unset($pendingArr[$cmd]);

						$this->Debug(
							'Pending',
							sprintf('WRITE 0x%04X durch ACK abgeschlossen', $cmd),
							0
						);
						continue;
					}

					// READ-PendingRequest: ACK erhalten → Warte auf Daten
					if (($info['type'] ?? '') === 'read' && empty($info['ackReceived'])) {

						$info['ackReceived'] = true;

						// timestamp wird neu gesetzt:
						// → Start von ReadDataTimeout
						$info['timestamp']  = time();

						$this->Debug(
							'Pending',
							sprintf(
								'READ 0x%04X ACK erhalten → starte ReadDataTimeout',
								$cmd
							),
							0
						);
					}
				}
				unset($info); // Referenz sauber lösen

				// <<< WICHTIG: hier werden die Statusänderungen gespeichert
				$this->SetBuffer('PendingRequests', json_encode($pendingArr));
				
				// Timer stoppen, wenn nichts mehr offen ist
				if (empty($pendingArr)) {
					$this->SetTimerInterval('PendingTimer', 0);
					$this->Debug('Timer','PendingTimer deaktiviert (alle PendingRequests erledigt)', 0);
				}

				// ACK aus dem Buffer entfernen
				$buffer = substr_replace($buffer, '', $ackPos, 2);
				continue;
			}

			/************************************************************
			 * 2) Start-Sequenz suchen
			 ************************************************************/
			$startPos = strpos($buffer, self::START);
			if ($startPos === false) {
				// kein gültiger Frameanfang → Buffer verwerfen
				$buffer = '';
				break;
			}

			// Alles vor START verwerfen
			if ($startPos > 0) {
				$buffer = substr($buffer, $startPos);
			}

			/************************************************************
			 * 3) Ende-Sequenz suchen
			 ************************************************************/
			$endPos = strpos($buffer, self::END, 2);
			if ($endPos === false) {
				// Frame noch unvollständig → warten auf mehr Daten
				break;
			}

			/************************************************************
			 * 4) Frame extrahieren
			 ************************************************************/
			$frame = substr($buffer, 0, $endPos + 2);

			$this->Debug('RX FRAME', $frame, 1);

			// Restbuffer behalten
			$buffer = substr($buffer, $endPos + 2);

			/************************************************************
			 * 5) Frame weiterverarbeiten
			 ************************************************************/
			$this->HandleFrame($frame);
		}

		// Restbuffer sichern
		$this->SetBuffer('RX', $buffer);
	}
	
	/**
	 * Verarbeitet einen vollständig empfangenen Datenframe.
	 *
	 * Prüft den Frame auf formale Gültigkeit (Länge, Checksumme), extrahiert
	 * Kommando und Nutzdaten, gleicht die Antwort mit offenen Pending-Requests ab,
	 * sendet das ACK zurück und übergibt das Kommando zur fachlichen Weiterverarbeitung.
	 *
	 * @param string $frame  Vollständiger, roher Datenframe inklusive Start- und Endekennung
	 * @return void
	 */
	private function HandleFrame(string $frame): void
	{
		$this->Debug('INFO', 'HandleFrame', 0);

		// Start/Ende entfernen
		$payload = substr($frame, 2, -2);

		// Mindestlänge prüfen
		if (strlen($payload) < 4) {
			$this->Debug('ERROR', 'Frame zu kurz', 0);
			return;
		}

		// Kommando lesen
		$cmdHi = ord($payload[0]);
		$cmdLo = ord($payload[1]);
		$command = ($cmdHi << 8) | $cmdLo;

		// Datenlänge
		$length = ord($payload[2]);

		// Daten extrahieren (Byte-Stuffing entfernen)
		$data = [];
		$i = 3;
		while ($i < strlen($payload) - 1 && count($data) < $length) {
			$byte = ord($payload[$i++]);

			if ($byte === 0x07 && isset($payload[$i]) && ord($payload[$i]) === 0x07) {
				$i++;
			}
			$data[] = $byte;
		}

		if (count($data) !== $length) {
			$this->Debug('ERROR',sprintf('Datenlänge falsch (erwartet=%d, erhalten=%d)', $length, count($data)),0);
			return;
		}

		// Checksumme prüfen
		$checksumRx = ord($payload[strlen($payload) - 1]);
		$checksum = $cmdHi + $cmdLo + $length + array_sum($data) + 173;
		$checksum &= 0xFF;

		if ($checksum !== $checksumRx) {
			$this->Debug('ERROR',sprintf('Checksumme falsch (RX=%02X / CALC=%02X)', $checksumRx, $checksum),0);
			return;
		}

		// PendingRequest nur löschen, wenn Response zu Read-Request passt
		$pending = $this->GetBuffer('PendingRequests');
		$pendingArr = $pending ? json_decode($pending, true) : [];

		$matchedRequest = null;

		foreach ($pendingArr as $reqCmd => $info) {
			foreach ($this->Commands as $cmdInfo) {
				if (
					$cmdInfo['command_request'] === $reqCmd &&
					$cmdInfo['command_response'] === $command
				) {
					$matchedRequest = $reqCmd;
					break 2;
				}
			}
		}

		if ($matchedRequest !== null) {
			unset($pendingArr[$matchedRequest]);
			$this->SetBuffer('PendingRequests', json_encode($pendingArr));

			$this->Debug('Pending',sprintf('READ Response 0x%04X zu Request 0x%04X vollständig → Pending gelöscht',$command,$matchedRequest),0);
			
			// vorherige Abfrage ist vollständig abgeschlossen → nächste senden falls noch etwas im Abfrage-Quee ist - deckt Idealpfad ab - siehe auch CheckPendingRequests()
			$this->TrySendNextCommand();
		} else {
			$this->Debug('Pending',sprintf('Response 0x%04X ohne passenden PendingRequest empfangen', $command),0);
		}

		// ACK erst jetzt senden (Frame gültig)
		$this->SendRaw(self::ACK);

		// Kommando-Debug
		$this->Debug('RX CMD', sprintf('0x%04X (%d Datenbytes)', $command, $length), 0);

		// Kommando weiterverarbeiten
		$this->DispatchCommand($command, $data);
	}

	/**
	 * Weist empfangene Kommando-Daten den entsprechenden Modulvariablen zu.
	 *
	 * Vergleicht das erhaltene Response-Kommando mit der internen
	 * Commands-Definition, extrahiert Werte aus den Datenbytes, wendet ggf.
	 * Postprocessing an und schreibt die Ergebnisse in die zugehörigen Variablen.
	 *
	 * @param int   $command  Empfangenes Response-Kommando
	 * @param array $data     Nutzdaten des Kommandos als Byte-Array
	 * @return void
	 */
	private function DispatchCommand(int $command, array $data): void
	{
		$this->Debug('DISPATCH',sprintf('DispatchCommand aufgerufen für CMD 0x%04X (%d Bytes)', $command, count($data)),0);
		
		foreach ($this->Commands as $cmdKey => $cmdInfo) {

			if ($command === $cmdInfo['command_response']) {			
				$this->Debug('DISPATCH',sprintf('MATCH → CMD 0x%04X gehört zu "%s" (%s)',$command,$cmdKey,$cmdInfo['command_type']),0);

				foreach ($cmdInfo['data'] as $byteIndex => $info) {

					if (!isset($data[$byteIndex - 1])) {
						continue;
					}

					$vid = @IPS_GetObjectIDByIdent($info['variable'], $this->InstanceID);
					if ($vid === false) {
						continue; // Variable existiert nicht
					}

					switch ($info['type']) {

						case 'percent':
						case 'integer':

							// ---------------------------------
							// Mehrbyte-Wert zusammenbauen
							// ---------------------------------
							$numBytes = $info['bytes'] ?? 1;
							$raw = 0;

							for ($b = 0; $b < $numBytes; $b++) {
								$raw = ($raw << 8) | ($data[$byteIndex - 1 + $b] ?? 0);
							}

							$value = $raw;

							// ---------------------------------
							// Postprocessing (NEU)
							// ---------------------------------
							if (isset($info['postprocessing'])) {
								switch ($info['postprocessing']) {

									case 'rpm_inverse_1875000':
										$value = ($raw > 0)
											? (int) round(1875000 / $raw)
											: 0;
										break;

									// weitere Integer-Postprocessings hier
								}
							}

							$this->SetValue($info['variable'], $value);

							$this->SendDebug('VALUE',sprintf('%s: raw=%d → value=%d (%s)',$info['variable'],$raw,$value,$info['postprocessing'] ?? 'none'),0);
							break;

						case 'float':

							$raw = $data[$byteIndex - 1];
							$value = $raw;

							if (isset($info['postprocessing'])) {
								switch ($info['postprocessing']) {

									case 'temp_half_minus_20':
										$value = ($raw / 2) - 20;
										break;

									// zukünftige Float-Konvertierungen hier
								}
							}

							$this->SetValue($info['variable'], (float)$value);

							$this->SendDebug('VALUE',sprintf('%s: raw=%d → value=%.2f (%s)',$info['variable'],$raw,$value,$info['postprocessing'] ?? 'none'),0);
							break;

						case 'boolean':
							$this->SetValue($info['variable'], $data[$byteIndex - 1] != 0);
							break;

						case 'string':
							$this->SetValue($info['variable'], chr($data[$byteIndex - 1]));
							break;
					}
				}

				//IPS_LogMessage('ComfoAir RX', sprintf('%s empfangen', $cmdKey));
				$this->Debug($cmdKey, json_encode($data), 1);
				return;
			}
		}

		// Fallback
		//IPS_LogMessage('ComfoAir RX',sprintf('Kommando 0x%04X Daten: %s',$command,bin2hex(pack('C*', ...$data))));
		$this->Debug('DISPATCH',sprintf('UNHANDLED → CMD 0x%04X (%d Bytes) | kein command_response-Match',$command,count($data)),0);
	}

	/**
	 * Prüft offene Pending-Requests und steuert Retry-Logik.
	 *
	 * Wird periodisch vom PendingTimer aufgerufen, erkennt Timeouts für ACKs
	 * und Daten, löst bei Bedarf erneutes Senden aus und entfernt abgebrochene
	 * Requests nach überschrittener MaxRetry-Anzahl.
	 *
	 * @return void
	 */
	public function CheckPendingRequests(): void
	{
		$pending = $this->GetBuffer('PendingRequests');
		$pendingArr = $pending ? json_decode($pending, true) : [];

		$timeoutAck  = $this->ReadAttributeInteger('RequestTimeout');
		$timeoutRead = $this->ReadAttributeInteger('ReadDataTimeout');
		$maxRetry    = $this->ReadAttributeInteger('MaxRetry');

		foreach ($pendingArr as $cmd => $info) {

			// PHASE 1: ACK noch nicht erhalten
			if (empty($info['ackReceived'])) {

				if (time() - $info['timestamp'] > $timeoutAck) {

					$info['retryCount']++;

					if ($info['retryCount'] > $maxRetry) {
						$this->Debug(
							'ERROR',
							sprintf('READ 0x%04X kein ACK nach %d Versuchen', $cmd, $maxRetry),
							0
						);
						unset($pendingArr[$cmd]);
						continue;
					}

					$this->Debug(
						'RETRY',
						sprintf('READ 0x%04X erneut senden (ACK Timeout)', $cmd),
						0
					);

					$this->SendCommand($cmd, $info['data']);
					$info['timestamp'] = time();
					$pendingArr[$cmd] = $info;
				}

				continue;
			}

			// PHASE 2: ACK erhalten, aber noch keine Daten
			if (($info['type'] ?? '') === 'read') {

				if (time() - $info['timestamp'] > $timeoutRead) {

					$info['retryCount']++;

					if ($info['retryCount'] > $maxRetry) {
						$this->Debug(
							'ERROR',
							sprintf('READ 0x%04X keine Daten nach %d Versuchen', $cmd, $maxRetry),
							0
						);
						unset($pendingArr[$cmd]);
						continue;
					}

					$this->Debug(
						'RETRY',
						sprintf('READ 0x%04X erneut senden (Daten Timeout)', $cmd),
						0
					);

					// Read erneut anfordern
					$info['ackReceived'] = false;
					$info['timestamp']  = time();
					$this->SendCommand($cmd, $info['data']);
					$pendingArr[$cmd] = $info;
				}
			}
		}

		$this->SetBuffer('PendingRequests', json_encode($pendingArr));
		
		// Timer deaktivieren, wenn keine PendingRequests mehr existieren
		if (empty($pendingArr)) {
			$this->SetTimerInterval('PendingTimer', 0);
			$this->Debug('Timer','PendingTimer deaktiviert (keine PendingRequests mehr)',0);
			
			// Falls noch Befehle in der Queue sind → sofort weiterarbeiten → nächste senden falls noch etwas im Abfrage-Quee ist - deckt Fehler-/Timeoutpfade ab
			$this->TrySendNextCommand();
		}
		
	}

	/**
	 * Legt Modulvariablen basierend auf der AutoRead-Konfiguration an.
	 *
	 * Erstellt neue Variablen für aktivierte Read-Gruppen, entfernt
	 * deaktivierte oder nicht mehr verwendete Variablen und wendet
	 * passende Profil- und Action-Einstellungen an.
	 *
	 * @param array $config  AutoRead-Konfiguration mit Gruppen, Variablen und Aktivierungsstatus
	 * @return void
	 */
	private function RegisterModuleVariables(array $config): void
	{
		// === Normalisierung ReadGroupsConfig['variables'] ===
		foreach ($config as $groupName => &$group) {
			if (isset($group['variables']) && is_array($group['variables'])) {

				// Falls SelectMultiple ein numerisches Array geliefert hat
				if (array_keys($group['variables']) === range(0, count($group['variables']) - 1)) {
					$normalized = [];
					foreach ($group['variables'] as $ident) {
						$normalized[$ident] = true;
					}
					$group['variables'] = $normalized;
				}
			}
		}
		unset($group);

		foreach ($this->Commands as $groupName => $cmdInfo) {

			// Nur Read-Gruppen
			if (($cmdInfo['command_type'] ?? '') !== 'read') {
				continue;
			}

			// Gruppe nicht konfiguriert oder deaktiviert
			if (
				!isset($config[$groupName]) ||
				!($config[$groupName]['enabled'] ?? false)
			) {
				$this->RemoveGroupVariables($groupName);
				continue;
			}

			foreach ($cmdInfo['data'] as $byteIndex => $info) {
				$ident = $info['variable'];

				// Variable laut Konfiguration deaktiviert
				if (!($config[$groupName]['variables'][$ident] ?? false)) {
					if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
						$this->SendDebug('RegisterModuleVariables', "Entferne Variable $ident (deaktiviert)", 0);
						IPS_DeleteVariable(IPS_GetObjectIDByIdent($ident, $this->InstanceID));
					}
					continue;
				}

				// Variable existiert bereits
				if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
					continue;
				}
/**	// bei Verwendung der Standardprofile
				switch ($info['type']) {
					case 'boolean':
						$vid = $this->RegisterVariableBoolean($ident, $info['name'], "~Switch");
						break;
					case 'integer':
						$vid = $this->RegisterVariableInteger($ident, $info['name'], "");
						break;
					case 'percent':
						$vid = $this->RegisterVariableInteger($ident, $info['name'], "~Intensity.100");
						break;
					case 'float':
						$vid = $this->RegisterVariableFloat($ident, $info['name'], "~Temperature");
						break;
					case 'string':
					default:
						$vid = $this->RegisterVariableString($ident, $info['name']);
						break;
				}
*/
				switch ($info['type']) {
					case 'boolean':
						$profile = $info['profile'] ?? "~Switch";
						$vid = $this->RegisterVariableBoolean($ident, $info['name'], $profile);
						break;
					case 'integer':
						$profile = $info['profile'] ?? "";
						$vid = $this->RegisterVariableInteger($ident, $info['name'], $profile);
						break;
					case 'percent':
						$profile = $info['profile'] ?? "~Intensity.100";
						$vid = $this->RegisterVariableInteger($ident, $info['name'], $profile);
						break;
					case 'float':
						$profile = $info['profile'] ?? "~Temperature";
						$vid = $this->RegisterVariableFloat($ident, $info['name'], $profile);
						break;
					case 'string':
					default:
						$profile = $info['profile'] ?? "";
						$vid = $this->RegisterVariableString($ident, $info['name'], $profile);
						break;
				}

				if (!empty($info['action']) && $info['action'] === true) {
					$this->EnableAction($ident);
				}

				$this->SendDebug('RegisterModuleVariables', sprintf('Variable %s (%s) angelegt', $ident, $info['name']), 0);
			}
		}
	}

	/**
	 * Entfernt alle Variablen einer bestimmten Read-Gruppe.
	 *
	 * Löscht die Modulvariablen der angegebenen Gruppe, z. B. wenn die Gruppe
	 * deaktiviert oder nicht mehr konfiguriert ist, und protokolliert die Löschvorgänge.
	 *
	 * @param string $groupName  Name der Read-Gruppe
	 * @return void
	 */
	private function RemoveGroupVariables(string $groupName): void
	{
		if (!isset($this->Commands[$groupName]['data'])) {
			$this->SendDebug(__FUNCTION__, "Gruppe $groupName existiert nicht (Skip)", 0);
			return;
		}

		foreach ($this->Commands[$groupName]['data'] as $info) {
			$ident = $info['variable'];
			if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
				$this->SendDebug(__FUNCTION__, "Entferne Variable $ident (Gruppe deaktiviert)", 0);
				IPS_DeleteVariable(IPS_GetObjectIDByIdent($ident, $this->InstanceID));
			}
		}
	}

	/**
	 * Behandelt Benutzeraktionen auf Modulvariablen.
	 *
	 * Reagiert auf direkte Änderungen von Variablen (z. B. über WebFront oder Form),
	 * validiert Werte, wandelt sie ggf. in das passende Format um und sendet
	 * die entsprechenden Kommandos an das Gerät. Unterstützt spezifische Aktionen
	 * wie Ventilationsstufe, Komforttemperatur und Hitzesteuerung.
	 *
	 * @param string       $Ident  Ident der angepassten Variable
	 * @param int|float|bool $Value Neuer Wert der Variable
	 * @return void
	 */
	public function RequestAction(string $Ident, mixed $Value): void
	{
		switch ($Ident) {
			case 'vsAktuelleStufe':
				// Range-Check: nur Werte 0-4 erlaubt
				$Value = max(0, min(4, (int)$Value));

				// Kommando ans Gerät senden (0x0099)
				$this->SendDebug('RequestAction', sprintf('Setze Ventilationsstufe auf %d', $Value), 0);
				$this->SendCommand(0x0099, [$Value]);

				// Variable sofort im Tree aktualisieren
				$this->SetValue('vsAktuelleStufe', $Value);	// sollte aber auch beim nächsten Request abgeglichen werden, wenn auf Intervallabfrage eingestellt

				// Hinweis: dieses Kommando liefert nur ACK, keine Response-Daten
				$this->SendDebug('RequestAction', 'Schreibkommando 0x0099 gesendet (ACK erwartet)', 0);
				break;
			case 'teKomfortTemperatur':
				// Range-Check: nur Werte 10-28 Grad erlaubt
				$Value = max(10.0, min(28.0, (float)$Value));
				$Value = round($Value * 2) / 2;   // auf 0,5°C runden, da nur ganzahlige Werte nach RAW übertragen werden können, hier absichern, wenn dennoch etwas anderes übergeben wird
				$raw = (int)(($Value + 20) * 2);	// Umrechnen auf Wert den die Lüftungsanlage verarbeiten kann - muss ganzzahlig sein

				// Kommando ans Gerät senden (0x00D3)
				$this->SendDebug('RequestAction', sprintf('Setze Komforttemperatur auf %.1f°C (RAW=%d)', $Value, $raw), 0);
				$this->SendCommand(0x00D3, [$raw]);

				// Variable sofort im Tree aktualisieren
				$this->SetValue('teKomfortTemperatur', $Value);	// sollte aber auch beim nächsten Request abgeglichen werden, wenn auf Intervallabfrage eingestellt

				// Hinweis: dieses Kommando liefert nur ACK, keine Response-Daten
				$this->SendDebug('RequestAction', 'Schreibkommando 0x00D3 gesendet (ACK erwartet)', 0);
				break;
			case 'Hitzesteuerung':
				$Value = (bool)$Value;

				$this->SetValue('Hitzesteuerung', $Value);

				if ($Value) {
					// EIN → Timer wieder starten
					$this->SetTimerInterval('HeatControlTimer',self::HEAT_CONTROL_INTERVAL);
					$this->SendDebug('HeatControl','Hitzesteuerung aktiviert → Timer gestartet',0);
					// optional: sofort prüfen
					$this->CheckHeatControl();
				} else {
					// AUS → Timer stoppen
					$this->SetTimerInterval('HeatControlTimer', 0);
					$this->SendDebug('HeatControl','Hitzesteuerung deaktiviert → Timer gestoppt',0);
					// ggf. Lüftung wieder freigeben
					$this->ReleaseHeatStop();
				}
				break;
		}
	}

	/**
	 * Plant und startet AutoRead-Abfragen für konfigurierbare Gruppen.
	 *
	 * Prüft, welche Read-Gruppen aktiviert und fällig sind, trägt die entsprechenden
	 * Kommandos in die Command-Queue ein und startet die Abarbeitung. Überspringt
	 * Gruppen, die deaktiviert sind, kein Intervall haben oder nicht pollbar sind.
	 *
	 * @return void
	 */
	public function AutoRead(): void
	{
		if (!$this->ReadPropertyBoolean('AutoReadEnabled')) {
			$this->SendDebug(__FUNCTION__, 'AutoRead global deaktiviert', 0);
			return;
		}

		$config = json_decode($this->ReadAttributeString('ReadGroupsConfig'), true) ?? [];
		$queue  = json_decode($this->GetBuffer('CommandQueue'), true) ?? [];
		$now    = time();

		foreach ($config as $groupName => &$group) {

			if (
				!$group['enabled'] ||
				$group['interval'] <= 0 ||
				!($this->Commands[$groupName]['pollable'] ?? false)
			) {
				continue;
			}

			if (($now - $group['lastRun']) < $group['interval']) {
				continue;
			}

			$cmd = $this->Commands[$groupName]['command_request'];

			// <<< ÄNDERUNG: nur in Queue eintragen
			$queue[] = [
				'group' => $groupName,
				'cmd'   => $cmd
			];

			$group['lastRun'] = $now;

			$this->SendDebug(__FUNCTION__,"AutoRead geplant: $groupName",0);
		}

		$this->SetBuffer('CommandQueue', json_encode($queue));
		$this->WriteAttributeString('ReadGroupsConfig', json_encode($config));

		// <<< ÄNDERUNG: Sendeversuch starten
		$this->TrySendNextCommand();
	}

	/**
	 * Versucht, das nächste Kommando aus der Queue zu senden.
	 *
	 * Prüft, ob aktuell Pending-Requests bestehen, holt das nächste Kommando aus
	 * der Command-Queue, sendet es an das Gerät und legt bei Bedarf einen neuen
	 * Pending-Request für Retry- und Timeout-Management an.
	 *
	 * @return void
	 */
	private function TrySendNextCommand(): void
	{
		$pending = json_decode($this->GetBuffer('PendingRequests'), true) ?? [];

		// <<< ÄNDERUNG: Bus ist belegt → nichts tun
		if (!empty($pending)) {
			$this->SendDebug(__FUNCTION__, 'Pending aktiv – sende nichts', 0);
			return;
		}

		$queue = json_decode($this->GetBuffer('CommandQueue'), true) ?? [];

		if (empty($queue)) {
			$this->SendDebug(__FUNCTION__, 'Queue leer', 0);
			return;
		}

		// <<< ÄNDERUNG: nächstes Kommando aus Queue holen
		$entry = array_shift($queue);
		$this->SetBuffer('CommandQueue', json_encode($queue));

		$this->SendDebug(__FUNCTION__,sprintf('Sende Kommando %s (0x%04X)', $entry['group'], $entry['cmd']),0);
		
		// Beispielaufruf: $this->SendCommand(0x00CD, []);
		$this->SendCommand($entry['cmd'], []);

		// Pending anlegen
		$pending[$entry['cmd']] = [
			'type'         => 'read',
			'timestamp'    => time(),
			'retryCount'   => 0,
			'ackReceived'  => false,
			'data'         => []
		];

		$this->SetBuffer('PendingRequests', json_encode($pending));
		$this->SetTimerInterval('PendingTimer', 1000);
	}

	/**
	 * Aktualisiert den Timer für den AutoRead-Scheduler.
	 *
	 * Berechnet das minimale Intervall aller aktiven, pollbaren Gruppen und
	 * setzt den Scheduler-Timer entsprechend. Deaktiviert den Timer, wenn
	 * keine Gruppen aktiv oder AutoRead global deaktiviert ist.
	 *
	 * @return void
	 */
	protected function UpdateAutoReadScheduler(): void
	{
		$config = json_decode($this->ReadAttributeString('ReadGroupsConfig'), true) ?? [];

		$intervals = [];

		foreach ($config as $group) {
			if ($group['enabled'] && $group['interval'] > 0) {
				$intervals[] = $group['interval'];
			}
		}

		if (empty($intervals) || !$this->ReadPropertyBoolean('AutoReadEnabled')) {
			$this->SetTimerInterval('AutoReadScheduler', 0);
			$this->SendDebug(__FUNCTION__, 'AutoReadScheduler deaktiviert', 0);
			return;
		}

		$base = min($intervals);
		$this->SetTimerInterval('AutoReadScheduler', $base * 1000);

		$this->SendDebug(__FUNCTION__, "AutoReadScheduler gesetzt auf {$base}s", 0);
	}

	/**
	 * Liefert das Konfigurationsformular für das Modul zurück.
	 *
	 * Stellt alle statischen und dynamischen Elemente für WebFront oder die
	 * Instanz-Konfiguration bereit, inklusive Hitzesteuerung, AutoRead-Grundeinstellungen
	 * und dynamisch generierten Read-Gruppen mit Intervall- und Variablenoptionen.
	 *
	 * @return string JSON-kodiertes Formular
	 */
	public function GetConfigurationForm(): string
	{
		$form = [
			'elements' => [],
			'actions'  => []
		];

		// =================================================
		// Hitzesteuerung
		// =================================================
		$form['elements'][] = [
			'type'    => 'Label',
			'bold'    => true,
			'caption' => 'Hitzesteuerung – Temperaturquellen'
		];

		$form['elements'][] = [
			'type'    => 'Label',
			'caption' => 'Wenn die Innentemperatur oder die Außentemperatur nicht mit einem Wert versehen sind, dann ist die Hitzesteuerung, sowie der Timer zu dieser Funktionalität inaktiv. Das Intervall beträgt, soweit die Funktionalität aktiv ist, 15 Minuten. Das heißt, dass alle 15 Minuten auf Hitze geprüft wird. Ist Hitze vorhanden, dann wird die Anlage auf Stufe 0 gestellt. Hitze liegt vor, wenn Innentemperatur > Komforttemperatur UND Außentemperatur > Innentemperatur'
		];
		
		$form['elements'][] = [
			'type'     => 'SelectVariable',
			'name'     => 'InsideTempVarID',
			'caption'  => 'Innentemperatur',
			'filter'   => [
				'moduleID' => '{485D0419-BE97-4548-AA9C-C083EB82E61E}'
			]
		];

		$form['elements'][] = [
			'type'     => 'SelectVariable',
			'name'     => 'OutsideTempVarID',
			'caption'  => 'Außentemperatur',
			'filter'   => [
				'moduleID' => '{485D0419-BE97-4548-AA9C-C083EB82E61E}'
			]
		];

		$form['elements'][] = [
			'type'    => 'Label',
			'caption' => '=============================================================='
		];

		// =================================================
		// AutoRead Grundoptionen
		// =================================================
		$form['elements'][] = [
			'type'    => 'Label',
			'bold'    => true,
			'caption' => 'Auswahl der Empfangsdaten von der Lüftungsanlage'
		];
		
		$form['elements'][] = [
			'type'    => 'CheckBox',
			'name'    => 'AutoReadEnabled',
			'caption' => 'Automatisches Auslesen aktiv'
		];

		$form['elements'][] = [
			'type'    => 'Label',
			'caption' => 'Hier kann festgelegt werden, welche Daten automatisch aus dem Gerät gelesen werden sollen und in welchem Intervall...'
		];

		//$form['actions'][] = [
		//	'type'    => 'Button',
		//	'caption' => 'Gerätetyp abfragen (0x00CD)',
		//	'onClick' => 'CAMCS_SendTestCommand($id);'
		//];
		// ----------------------------------------------------------------------
		// Actions-Bereich
		// ----------------------------------------------------------------------
		$form['actions'] = [
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "Diese Software steht unter der Apache-2.0-Lizenz und ist sowohl privat als auch kommerziell kostenlos nutzbar. Sie kann von dir genutzt werden, ohne dass Lizenzgebühren anfallen. Jegliche Haftung für Schäden ist ausgeschlossen. Weitere Informationen zum Modul und seiner Funktionsweise finden Sie auf GitHub: https://github.com/BugForgeNerd/ComfoAirManager"
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"bold"    => true,
				"caption" => "Spenden zur Stärkung der OpenSource Entwickler"
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "Open Source bietet Kostenersparnis, hohe Sicherheit, Transparenz und Unabhängigkeit von Herstellern. Durch den offenen Quellcode ist Software flexibel anpassbar, interoperabel und profitiert von einer aktiven Community, die Fehler schnell findet und behebt."
			],
			[
				"type"  => "RowLayout",
				"items" => [
					[
						"type"    => "Image",
						"onClick" => "echo '" . "https://www.paypal.com/donate/?hosted_button_id=GPLYXLH6AJYF8" . "';",
						"image"   => "data:image/png;base64,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"
					],
					[
						"type"    => "Label",
						"caption" => " "
					],
					[
						"type"    => "Label",
						"width"   => "70%",
						"caption" => "Wenn Sie mich unterstützen möchten, dann geht das ganz einfach und freiwillig unter dem folgenden Link."
					],
					[
						"type"    => "Label",
						"caption" => " "
					]
				]
			],
			[
				"type"    => "Label",
				"width"   => "50%",
				"caption" => "https://www.paypal.com/donate/?hosted_button_id=GPLYXLH6AJYF8"
			]
		];

		// =================================================
		// Dynamische Command-Gruppen
		// =================================================
		foreach ($this->Commands as $groupName => $cmd) {
			if (($cmd['command_type'] ?? '') !== 'read') {
				continue;
			}

			$key = $cmd['key'];

			$form['elements'][] = [
				'type'    => 'Label',
				'caption' => '________________________________________________________'
			];

			$form['elements'][] = [
				'type'    => 'Label',
				'bold'    => true,
				'caption' => "Gruppe: $groupName"
			];

			$form['elements'][] = [
				'type'    => 'Label',
				'caption' => "Inhalte: " . ($cmd['description'] ?? 'Keine Beschreibung')
			];

			$form['elements'][] = [
				'type'    => 'Select',
				'name'    => "AutoReadInterval_{$key}",
				'caption' => 'Intervall',
				'value'   => $this->ReadPropertyInteger("AutoReadInterval_{$key}"),
				'options' => [
					['caption' => 'Aus',      'value' => 0],
					['caption' => '1 Minute', 'value' => 60],
					['caption' => '1 Stunde', 'value' => 3600],
					['caption' => '1 Tag',    'value' => 86400]
				]
			];

			foreach ($cmd['data'] as $info) {
				$ident     = $info['variable'];
				$isDefault = $info['default'] ?? false;

				$form['elements'][] = [
					'type'  => 'RowLayout',
					'items' => [
						[
							'type'    => 'CheckBox',
							'name'    => "AutoRead_{$key}_{$ident}",
							'value'   => $this->ReadPropertyBoolean("AutoRead_{$key}_{$ident}"),
							'enabled' => !$isDefault
						],
						[
							'type'    => 'Label',
							'caption' => $info['name']
						]
					]
				];
			}
		}

		return json_encode($form);
	}

	/**
	 * Prüft und legt IPS-Variablenprofile an.
	 * 
	 * - Legt Standardprofile an oder überschreibt diese im DevMode.
	 * - Stellt sicher, dass alle in Commands referenzierten Profile existieren.
	 * - Setzt Float-, Integer- und Boolean-Profile korrekt inklusive Text, Min/Max/Step und Associations.
	 *
	 * Parameter: keine
	 */
	public function ProfilCheck(): void
	{
		$devMode = false; // true = Dev-Modus (vorhandene Profile dürfen überschrieben werden)

		// -------------------------------------------------
		// Zentrale Profildefinitionen (Single Source of Truth)
		// -------------------------------------------------
		$standardProfiles = [
			"Comfo_Temperatur" => ["Type"   => 2, "Icon"   => "Temperature", "Text"   => ["", " °C"], "Min"    => 5, "Max"    => 35, "Step"   => 0.5, "Digits" => 1 ],
			"Comfo_Percent" => ["Type"  => 1, "Icon"  => "Repeat", "Text"   => ["", " %"], "Min"   => 0, "Max"   => 100, "Step"  => 1,],
			"Comfo_Drehzahl" => ["Type"  => 1, "Icon"  => "Ventilation", "Text"   => ["", " U/min"], "Min"   => 0, "Max"   => 5000, "Step"  => 1],

			"Comfo_Sommermodus" => ["Type"  => 0, "Icon"  => "Power",
				"Associations" => [
					[0, "Winter", "", 0xA9C1FC],
					[1, "Sommer", "", 0xFCF403]
				]
			],

			"Comfo_BypassStufe" => ["Type"  => 1, "Icon"  => "Repeat", "Min"   => 0, "Max"   => 1, "Step"  => 1,
				"Associations" => [
					[0, "kein Bypass"],
					[1, "Bypass geschaltet"]
				]
			],

			"Comfo_LueftungsStufe" => ["Type"  => 1, "Icon"  => "Ventilation", "Min"   => 0, "Max"   => 4, "Step"  => 0,
				"Associations" => [
					[1, "Aus", "", 0xFF0000],
					[2, "Stufe 1 (niedrig)", "", 0x6DBEE4],
					[3, "Stufe 2 (mittel)", "", 0x1869E2],
					[4, "Stufe 3 (hoch)", "", 0x1E407D]
				]
			],

			"Comfo_Filterstatus" => ["Type"  => 0, "Icon"  => "Power",
				"Associations" => [
					[0, "Filter ok", "", 0x8BF279],
					[1, "Filter voll", "", 0xF8163E]
				]
			]
		];

		// -------------------------------------------------
		// 1) Standardprofile anlegen & konfigurieren
		// -------------------------------------------------
		foreach ($standardProfiles as $name => $cfg) {

			$exists = IPS_VariableProfileExists($name);

			if (!$exists) {
				$this->SendDebug(__FUNCTION__, "Profil >{$name}< existiert nicht, wird angelegt.", 0);
				IPS_CreateVariableProfile($name, $cfg['Type']);
			}

			// Eigenschaften NUR setzen:
			// - wenn Profil neu ist
			// - oder wenn DevMode aktiv
			if (!$exists || $devMode) {

				IPS_SetVariableProfileIcon($name, $cfg['Icon'] ?? "");

				switch ($cfg['Type']) {

					case 2: // Float
						IPS_SetVariableProfileText($name, $cfg['Text'][0] ?? "", $cfg['Text'][1] ?? "");
						IPS_SetVariableProfileValues($name, $cfg['Min'], $cfg['Max'], $cfg['Step']);
						IPS_SetVariableProfileDigits($name, $cfg['Digits'] ?? 0);
						break;

					case 1: // Integer
						IPS_SetVariableProfileValues($name, $cfg['Min'], $cfg['Max'], $cfg['Step']);
						if (!empty($cfg['Text'])) {
							IPS_SetVariableProfileText($name, $cfg['Text'][0], $cfg['Text'][1]);
						}
						break;
				}

				// Associations sauber neu setzen (nur wenn definiert)
				if (!empty($cfg['Associations'])) {
					if ($cfg['Type'] != 0 && $exists && $devMode) {
						// Nur bei Integer/Float alte Associations löschen
						$old = IPS_GetVariableProfile($name)['Associations'];
						foreach ($old as $assoc) {
							IPS_SetVariableProfileAssociation($name, $assoc['Value'], '', '', -1);
						}
					}

					// Neue Associations setzen
					foreach ($cfg['Associations'] as $assoc) {
						IPS_SetVariableProfileAssociation(
							$name,
							$assoc[0],
							$assoc[1],
							$assoc[2] ?? "",
							$assoc[3] ?? -1
						);
					}
				}
			}
		}

		// -------------------------------------------------
		// 2) Profile aus Commands sicherstellen (NUR anlegen)
		// -------------------------------------------------
		foreach ($this->Commands as $group => $cmd) {
			foreach ($cmd['data'] as $info) {

				if (empty($info['profile'])) {
					continue;
				}

				$profile = $info['profile'];

				if (!IPS_VariableProfileExists($profile)) {

					$this->SendDebug(__FUNCTION__, "Profil >{$profile}< aus Commands existiert nicht, wird angelegt.", 0);

					$type = match ($info['type']) {
						'float'   => 2,
						'integer' => 1,
						'boolean' => 0,
						default   => 1
					};

					IPS_CreateVariableProfile($profile, $type);
				}

			}
		}
	}

	/**
	 * Legt einen Wochenplan-Event für die Hitzesteuerung an.
	 * 
	 * - Erstellt ein IPS-Event unter dem Modul als Parent, falls noch nicht vorhanden.
	 * - Definiert 4 Stufen der Lüftungsanlage (Aus, Stufe 1-3) mit festen Zeitgruppen.
	 * - Jede Stufe führt ein SetRequestAction-Kommando an das Modul aus.
	 *
	 * Parameter: keine
	 */
	public function CreateWochenplan(): void
	{
		//$parentID = @$this->GetIDForIdent('vsAktuelleStufe');
		//if ($parentID === false) {
		//	return;
		//}
		
		// Modul-ID als Parent
		$parentID = $this->InstanceID;

		// Prüfen, ob schon vorhanden (über ObjectIDByIdent!)
		$existingID = @IPS_GetObjectIDByIdent('HeatControlSchedule', $parentID);
		if ($existingID !== false) {
			return;
		}

		$eventID = IPS_CreateEvent(2);
		IPS_SetParent($eventID, $parentID);
		IPS_SetIdent($eventID, 'HeatControlSchedule');
		IPS_SetName($eventID, 'Wochenplan Lüftungs-Stufe');
		IPS_SetEventActive($eventID, false);

		IPS_SetEventScheduleGroup($eventID, 0, 31);
		IPS_SetEventScheduleGroup($eventID, 1, 96);

		//IPS_SetEventScheduleAction($eventID, 0, 'Aus', 0xFF0000, "SetValue(\$_IPS['TARGET'], 1);");
		//IPS_SetEventScheduleAction($eventID, 1, 'Stufe 1 (niedrig)', 0x6DBEE4, "SetValue(\$_IPS['TARGET'], 2);");
		//IPS_SetEventScheduleAction($eventID, 2, 'Stufe 2 (mittel)', 0x1869E2, "SetValue(\$_IPS['TARGET'], 3);");
		//IPS_SetEventScheduleAction($eventID, 3, 'Stufe 3 (hoch)', 0x1E407D, "SetValue(\$_IPS['TARGET'], 4);");
		
		//IPS_SetEventScheduleActionEx($eventID, 0, 'Aus', 0xFF0000, '{3644F802-C152-464A-868A-242C2A3DEC5C}', ["VALUE" => 1]);
		//IPS_SetEventScheduleActionEx($eventID, 1, 'Stufe 1 (niedrig)', 0x6DBEE4, '{3644F802-C152-464A-868A-242C2A3DEC5C}', ["VALUE" => 2]);
		//IPS_SetEventScheduleActionEx($eventID, 2, 'Stufe 2 (mittel)', 0x1869E2, '{3644F802-C152-464A-868A-242C2A3DEC5C}', ["VALUE" => 3]);
		//IPS_SetEventScheduleActionEx($eventID, 3, 'Stufe 3 (hoch)', 0x1E407D, '{3644F802-C152-464A-868A-242C2A3DEC5C}', ["VALUE" => 4]); 
		
		// Aktionen: Modul-ID dynamisch verwenden
		$moduleID = $this->InstanceID;

		IPS_SetEventScheduleAction(
			$eventID, 0, 'Aus', 0xFF0000,
			'CAMCS_SetRequestActionInt(' . $moduleID . ', \'vsAktuelleStufe\', (int)1);'
		);
		IPS_SetEventScheduleAction(
			$eventID, 1, 'Stufe 1 (niedrig)', 0x6DBEE4,
			'CAMCS_SetRequestActionInt(' . $moduleID . ', \'vsAktuelleStufe\', (int)2);'
		);
		IPS_SetEventScheduleAction(
			$eventID, 2, 'Stufe 2 (mittel)', 0x1869E2,
			'CAMCS_SetRequestActionInt(' . $moduleID . ', \'vsAktuelleStufe\', (int)3);'
		);
		IPS_SetEventScheduleAction(
			$eventID, 3, 'Stufe 3 (hoch)', 0x1E407D,
			'CAMCS_SetRequestActionInt(' . $moduleID . ', \'vsAktuelleStufe\', (int)4);'
		);

	}

	/**
	 * Helperfunktionen zur direkten Ausführung von RequestAction für verschiedene Datentypen.
	 * - Beispiel: CAMCS_SetRequestActionInt()
	 * - Dienen als Schnittstelle für externe Aufrufe, z.B. aus Wochenplan-Events.
	 * - Rufen intern RequestAction mit dem jeweiligen Typ auf.
	 *
	 * Parameter:
	 *   - SetRequestActionInt(string $Ident, int $Value)     → Ident der Variable, ganzzahliger Wert
	 *   - SetRequestActionBool(string $Ident, bool $Value)   → Ident der Variable, boolescher Wert
	 *   - SetRequestActionString(string $Ident, string $Value) → Ident der Variable, String-Wert
	 *   - SetRequestActionFloat(string $Ident, float $Value) → Ident der Variable, Float-Wert
	 */
	public function SetRequestActionInt(string $Ident, int $Value): void
	{
		$this->RequestAction($Ident, $Value);
	}
	public function SetRequestActionBool(string $Ident, bool $Value): void
	{
		$this->RequestAction($Ident, $Value);
	}			
	public function SetRequestActionString(string $Ident, string $Value): void
	{
		$this->RequestAction($Ident, $Value);
	}
	public function SetRequestActionFloat(string $Ident, float $Value): void
	{
		$this->RequestAction($Ident, $Value);
	}

	/**
	 * Prüft die Hitzesteuerung der Lüftungsanlage.
	 * 
	 * - Öffentliche Methode: CheckHeatControl() → Einstiegspunkt.
	 * - Geschützte Methode: CheckHeatControlInternal() → Kernlogik.
	 * - Prüft, ob Hitzesteuerung aktiv ist und Sensorwerte verfügbar sind.
	 * - Vergleicht Innentemperatur, Außentemperatur und Komforttemperatur.
	 * - Bei Hitze wird Lüftung auf Stufe 0 gesetzt, sonst Freigabe.
	 *
	 * Parameter:
	 *   Keine Parameter.
	 */	
	public function CheckHeatControl(): void
	{
		$this->CheckHeatControlInternal();
	}

	protected function CheckHeatControlInternal(): void
	{
		if (!$this->GetValue('Hitzesteuerung')) {
			$this->SetValue('LueftungAusWegenHitze', false);
			$this->SendDebug('HeatControl', 'Deaktiviert durch Benutzer', 0);
			return;
		}

		$insideVar = $this->ReadPropertyInteger('InsideTempVarID');
		$outsideVar = $this->ReadPropertyInteger('OutsideTempVarID');
		
		// zunächst schauen, ob Benutzer Variablen im Konfigurationsformular ausgewählt hat.
		if ($insideVar < 10000 || $outsideVar < 10000) {
			$this->SendDebug('HeatControl', 'Abbruch: Sensoren nicht konfiguriert', 0);
			return;
		}

		$inside  = GetValueFloat($insideVar);
		$outside = GetValueFloat($outsideVar);
		$comfort = GetValueFloat($this->GetIDForIdent('teKomfortTemperatur'));
		
		// testen
		//$inside  = 27.0;
		//$outside = 30.0;

		$heatActive = ($inside > $comfort && $outside > $inside);

		$this->SendDebug('HeatControl',sprintf('Inside=%.1f Outside=%.1f Comfort=%.1f → %s',$inside,$outside,$comfort,$heatActive ? 'HITZE' : 'OK'),0);

		if ($heatActive) {
			$this->ApplyHeatStop();
		} else {
			$this->ReleaseHeatStop();
		}
	}

	/**
	 * Stoppt die Lüftung aufgrund von Hitze.
	 * 
	 * - Sichert die aktuelle Lüftungsstufe und Wochenplan-Aktivität.
	 * - Deaktiviert ggf. den Wochenplan.
	 * - Setzt die Lüftung auf Stufe 0 (Abwesend).
	 * - Setzt internen Status 'LueftungAusWegenHitze'.
	 *
	 * Parameter:
	 *   Keine Parameter.
	 */
	protected function ApplyHeatStop(): void
	{
		if ($this->GetValue('LueftungAusWegenHitze')) {
			return; // bereits aktiv
		}

		// aktuelle Stufe sichern (einmal!)
		$currentStage = $this->GetValue('vsAktuelleStufe');
		$this->WriteAttributeInteger('HeatControlPrevStage', $currentStage);

		// Wochenplan-Status sichern
		$eventID = @IPS_GetObjectIDByIdent('HeatControlSchedule', $this->InstanceID);
		if ($eventID !== false) {
			$wasActive = IPS_GetEvent($eventID)['EventActive'];
			$this->WriteAttributeBoolean('HeatControlPrevScheduleActive', $wasActive);
			if ($wasActive) {
				IPS_SetEventActive($eventID, false);
				$this->SendDebug('HeatControl','Wochenplan wegen Hitzestopp deaktiviert',0);
			}
		}
	
		$this->SetValue('LueftungAusWegenHitze', true);
		$this->SendDebug('HeatControl', 'Lüftung wegen Hitze gestoppt', 0);

		// Stufe 0 setzen (Abwesend)
		//$this->SendCommand(0x0099, [1]);
		$this->SetRequestActionInt('vsAktuelleStufe', 1);	// dann wird auch sofort die Variable im Tree mitgesetzt
	}

	/**
	 * Gibt die Lüftung nach einem Hitzestopp wieder frei.
	 * 
	 * - Setzt internen Status 'LueftungAusWegenHitze' zurück.
	 * - Aktiviert ggf. den Wochenplan wieder.
	 * - Stellt die Lüftungsstufe entweder aus dem Wochenplan oder aus dem zuvor gespeicherten Wert wieder her.
	 * - Setzt die aktuelle Stufe im Objektbaum.
	 *
	 * Parameter:
	 *   Keine Parameter.
	 */
	protected function ReleaseHeatStop(): void
	{
		if (!$this->GetValue('LueftungAusWegenHitze')) {
			return;
		}

		$this->SetValue('LueftungAusWegenHitze', false);

		// Wochenplan ggf. wieder aktivieren 
		$eventID = @IPS_GetObjectIDByIdent('HeatControlSchedule', $this->InstanceID);
		$scheduleWasActive = $this->ReadAttributeBoolean('HeatControlPrevScheduleActive');
		if ($scheduleWasActive && $eventID !== false) {
			IPS_SetEventActive($eventID, true);
			$this->SendDebug('HeatControl','Wochenplan wieder aktiviert',0);
		}
		
		// Wochenplan prüfen
		$stage = $this->GetStageFromSchedule();

		if ($stage !== null) {
			$this->SendDebug('HeatControl', 'Wochenplan aktiv → Stufe '.$stage, 0);
		} else {
			// gespeicherte Stufe verwenden
			$stage = $this->ReadAttributeInteger('HeatControlPrevStage');

			if ($stage < 0) {
				// Fallback (sollte praktisch nie passieren)
				$stage = $this->GetValue('vsAktuelleStufe');
				$this->SendDebug('HeatControl','Kein Speicherwert → Fallback aktuelle Stufe',0);
			} else {
				$this->SendDebug('HeatControl','Vorherige Stufe wiederhergestellt → '.$stage,0);
			}
		}

		// Attribute zurücksetzen
		$this->WriteAttributeInteger('HeatControlPrevStage', -1);
		$this->WriteAttributeBoolean('HeatControlPrevScheduleActive', false);
		 
		//$this->SendCommand(0x0099, [$stage]);
		$this->SetRequestActionInt('vsAktuelleStufe', $stage);		// dann wird auch sofort die Variable im Tree mitgesetzt
	}

	/**
	 * Ermittelt die aktuell aktive Lüftungsstufe aus dem Wochenplan.
	 * 
	 * - Prüft, ob der Wochenplan existiert und aktiv ist.
	 * - Bestimmt die aktuelle Aktion basierend auf Uhrzeit und Wochentag.
	 * - Gibt die entsprechende Stufe zurück (1-basierend, 0 = Aus).
	 *
	 * Rückgabewert:
	 *   int|null → aktuelle Stufe (1–4) oder null, wenn kein Wochenplan aktiv.
	 */
	protected function GetStageFromSchedule(): ?int
	{
		$parentID = $this->InstanceID;
		$eventID = @IPS_GetObjectIDByIdent('HeatControlSchedule', $parentID);
		if ($eventID === false || !IPS_GetEvent($eventID)['EventActive']) {
			return null;
		}
		$e = IPS_GetEvent($eventID);
		$actionID = null;
		$now = date("H") * 3600 + date("i") * 60 + date("s");
		foreach ($e['ScheduleGroups'] as $g) {
			if (($g['Days'] & (1 << (date("N") - 1))) > 0) {
				foreach ($g['Points'] as $p) {
					$start = $p['Start']['Hour'] * 3600
						   + $p['Start']['Minute'] * 60
						   + $p['Start']['Second'];
					if ($now >= $start) {
						$actionID = $p['ActionID'];
					} else {
						break;
					}
				}
				break;
			}
		}
		return $actionID !== null ? $actionID + 1 : null;  // Es wird die ID verwendet. 0 ist Aus also 1 und so weiter, daher +1
	}

}
?>