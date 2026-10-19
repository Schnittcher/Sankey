<?php

declare(strict_types=1);

class Sankey extends IPSModuleStrict
{
    private const ARCHIVE_CONTROL_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public function Create(): void
    {
        parent::Create();

        // -1 = Farbe der Visualisierung verwenden
        $this->RegisterPropertyInteger('ColorLabel', -1);
        $this->RegisterPropertyBoolean('ShowValues', false);
        $this->RegisterPropertyBoolean('StaticMode', false);
        $this->RegisterPropertyString('Links', '[]');

        $this->RegisterVariableInteger('StartDate', $this->Translate('Start date'), '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('EndDate', $this->Translate('End date'), '~UnixTimestamp', 11);
        $this->EnableAction('StartDate');
        $this->EnableAction('EndDate');

        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $staticMode = $this->ReadPropertyBoolean('StaticMode');

        // Datumsvariablen nur im statischen Modus sichtbar und bedienbar
        foreach (['StartDate', 'EndDate'] as $ident) {
            $id = $this->GetIDForIdent($ident);
            IPS_SetHidden($id, !$staticMode);
            IPS_SetDisabled($id, !$staticMode);
        }

        // Standardzeitraum setzen, wenn noch kein Wert vorhanden ist
        if ($staticMode && $this->GetValue('StartDate') === 0) {
            $now = time();
            $this->SetValue('StartDate', mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now)));
            $this->SetValue('EndDate', $now);
        }

        // Alle bisherigen Nachrichten abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Im Archivmodus entscheidet MessageSink(), ob der gewählte Zeitraum aktualisiert werden muss.
        foreach ($this->GetLinks() as $link) {
            foreach (['VariableID', 'InfoVariableID'] as $key) {
                $varID = (int) ($link[$key] ?? 0);

                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
        }

        $this->UpdateDiagram();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        if (!$this->ReadPropertyBoolean('StaticMode') || $this->IsCurrentTimeInsideSelectedRange()) {
            $this->UpdateDiagram();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'StartDate':
            case 'EndDate':
                $this->SetDateRangeValue($Ident, (int) $Value);
                $this->UpdateDiagram();
                break;

            case 'ToggleMode':
                IPS_SetProperty($this->InstanceID, 'StaticMode', (int) $Value === 1);
                IPS_ApplyChanges($this->InstanceID);
                break;

            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannter Ident: ' . $Ident, 0);
                break;
        }
    }

    public function UpdateDiagram(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->CollectData(), self::JSON_FLAGS));
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');

        // Startdaten an die Kachel übergeben
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->CollectData(), self::JSON_FLAGS), self::JSON_FLAGS) . ');</script>';
    }

    private function SetDateRangeValue(string $ident, int $value): void
    {
        if ($value <= 0) {
            $this->SendDebug(__FUNCTION__, 'Ungültiger Zeitstempel für ' . $ident, 0);
            return;
        }

        $start = $ident === 'StartDate' ? $value : $this->GetValue('StartDate');
        $end = $ident === 'EndDate' ? $value : $this->GetValue('EndDate');

        if ($start > $end) {
            $this->SendDebug(__FUNCTION__, 'Startdatum liegt nach dem Enddatum, Wert wird verworfen', 0);
            return;
        }

        $this->SetValue($ident, $value);
    }

    private function GetLinks(): array
    {
        $links = json_decode($this->ReadPropertyString('Links'), true);

        return is_array($links) ? $links : [];
    }

    private function IsCurrentTimeInsideSelectedRange(): bool
    {
        $startTime = $this->GetValue('StartDate');
        $endTime = $this->GetValue('EndDate');
        $now = time();

        if ($startTime <= 0 || $endTime <= 0) {
            return false;
        }

        return $startTime <= $now && $endTime >= $now;
    }

    private function GetVariableUnit(int $varID): string
    {
        // Neue Darstellungen haben Vorrang
        $presentation = IPS_GetVariablePresentation($varID);

        if (is_array($presentation) && !empty($presentation['SUFFIX'])) {
            return (string) $presentation['SUFFIX'];
        }

        // Klassisches Profil-System
        $profileName = $this->GetProfileName($varID);

        if ($profileName === '') {
            return '';
        }

        return IPS_GetVariableProfile($profileName)['Suffix'];
    }

    private function GetProfileName(int $varID): string
    {
        $varInfo = IPS_GetVariable($varID);
        $profileName = $varInfo['VariableCustomProfile'] !== ''
            ? $varInfo['VariableCustomProfile']
            : $varInfo['VariableProfile'];

        return ($profileName !== '' && IPS_VariableProfileExists($profileName)) ? $profileName : '';
    }

    private function GetAggregationLevel(int $startTime, int $endTime): int
    {
        $diff = $endTime - $startTime;

        if ($diff <= 2 * 86400) {
            return 0; // bis 2 Tage → stündlich
        }

        if ($diff <= 28 * 86400) {
            return 1; // bis 4 Wochen → täglich
        }

        if ($diff <= 180 * 86400) {
            return 2; // bis 6 Monate → wöchentlich
        }

        if ($diff <= 3 * 365 * 86400) {
            return 3; // bis 3 Jahre → monatlich
        }

        return 4; // über 3 Jahre → jährlich
    }

    private function GetArchiveID(): int
    {
        return IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL_GUID)[0] ?? 0;
    }

    /**
     * Liefert eine Liste aus ['value' => float, 'reverse' => bool, 'split' => bool].
     */
    private function GetValuesFromArchive(int $archiveID, int $varID, int $startTime, int $endTime): array
    {
        if ($archiveID === 0 || $startTime <= 0 || $endTime <= $startTime || !AC_GetLoggingStatus($archiveID, $varID)) {
            return [];
        }

        $isCounter = boolval(AC_GetAggregationType($archiveID, $varID));
        $level = $this->GetAggregationLevel($startTime, $endTime);

        $agg = AC_GetAggregatedValues($archiveID, $varID, $level, $startTime, $endTime, 0);

        if (empty($agg)) {
            $this->SendDebug(
                __FUNCTION__,
                sprintf('Keine Archivdaten für Variable "%s" (%d) im Zeitraum %s - %s', IPS_GetName($varID), $varID, date('d.m.Y', $startTime), date('d.m.Y', $endTime)),
                0
            );
            return [];
        }

        if ($isCounter) {
            $sum = 0.0;

            foreach ($agg as $a) {
                $sum += floatval($a['Avg']);
            }

            return [
                ['value' => $sum, 'reverse' => false, 'split' => false]
            ];
        }

        $posSum = 0.0;
        $posCnt = 0;
        $negSum = 0.0;
        $negCnt = 0;

        foreach ($agg as $a) {
            $avg = floatval($a['Avg']);

            if ($avg > 0) {
                $posSum += $avg;
                $posCnt++;
            } elseif ($avg < 0) {
                $negSum += abs($avg);
                $negCnt++;
            }
        }

        $hasBoth = $posCnt > 0 && $negCnt > 0;
        $result = [];

        if ($posCnt > 0) {
            $result[] = ['value' => $posSum / $posCnt, 'reverse' => false, 'split' => $hasBoth];
        }

        if ($negCnt > 0) {
            $result[] = ['value' => $negSum / $negCnt, 'reverse' => true, 'split' => $hasBoth];
        }

        return $result;
    }

    private function GetInfoText(int $archiveID, int $infoVarID, bool $staticMode, int $startTime, int $endTime): string
    {
        if ($infoVarID <= 0 || !IPS_VariableExists($infoVarID)) {
            return '';
        }

        if ($staticMode) {
            $infoValue = 0.0;

            foreach ($this->GetValuesFromArchive($archiveID, $infoVarID, $startTime, $endTime) as $entry) {
                $infoValue += floatval($entry['value']);
            }
        } else {
            $infoValue = abs(floatval(GetValue($infoVarID)));
        }

        if ($this->GetProfileName($infoVarID) !== '') {
            return GetValueFormattedEx($infoVarID, $infoValue);
        }

        $infoUnit = $this->GetVariableUnit($infoVarID);

        return number_format($infoValue, 2, ',', "'") . ($infoUnit !== '' ? ' ' . $infoUnit : '');
    }

    /**
     * Liefert die Daten für die Kachel. rows-Format: [source, target, value, unit, info].
     */
    private function CollectData(): array
    {
        $staticMode = $this->ReadPropertyBoolean('StaticMode');
        $colorLabel = $this->ReadPropertyInteger('ColorLabel');

        $rows = [];
        $nodeColorMap = [];

        $startTime = $staticMode ? $this->GetValue('StartDate') : 0;
        $endTime = $staticMode ? $this->GetValue('EndDate') : 0;
        $archiveID = $staticMode ? $this->GetArchiveID() : 0;

        foreach ($this->GetLinks() as $link) {
            $source = trim($link['Source'] ?? '');
            $target = trim($link['Target'] ?? '');
            $varID = (int) ($link['VariableID'] ?? 0);

            if ($source === '' || $target === '' || $varID <= 0 || !IPS_VariableExists($varID)) {
                continue;
            }

            if ($staticMode) {
                $values = $this->GetValuesFromArchive($archiveID, $varID, $startTime, $endTime);
            } else {
                $v = floatval(GetValue($varID));
                $values = [
                    ['value' => abs($v), 'reverse' => $v < 0, 'split' => false]
                ];
            }

            $invert = boolval($link['Invert'] ?? false);
            $ignoreNegative = boolval($link['IgnoreNegative'] ?? false);
            $color = (int) ($link['Color'] ?? 0);
            $unit = null;
            $infoText = null;

            foreach ($values as $entry) {
                $value = floatval($entry['value']);
                $reverse = boolval($entry['reverse']);
                $split = boolval($entry['split']);

                if ($invert) {
                    $reverse = !$reverse;
                }

                if ($value <= 0) {
                    continue;
                }

                if ($reverse) {
                    if ($ignoreNegative) {
                        continue;
                    }

                    $rowSource = $target;
                    $rowTarget = $split ? $source . '-' : $source;
                } else {
                    $rowSource = $source;
                    $rowTarget = $target;
                }

                $unit ??= $this->GetVariableUnit($varID);
                $infoText ??= $this->GetInfoText($archiveID, (int) ($link['InfoVariableID'] ?? 0), $staticMode, $startTime, $endTime);

                $rows[] = [$rowSource, $rowTarget, $value, $unit, $infoText];

                if ($color > 0) {
                    $hex = sprintf('#%06x', $color);
                    $nodeColorMap[$rowSource] ??= $hex;
                    $nodeColorMap[$rowTarget] ??= $hex;
                }
            }
        }

        return [
            'rows'       => $rows,
            'nodeColors' => (object) $nodeColorMap,
            'staticMode' => $staticMode,
            'startTs'    => $staticMode ? $startTime : null,
            'endTs'      => $staticMode ? $endTime : null,
            'labelColor' => $colorLabel >= 0 ? sprintf('#%06x', $colorLabel) : '',
            'showValues' => $this->ReadPropertyBoolean('ShowValues'),
            'texts'      => [
                'archive'  => $this->Translate('Archive'),
                'live'     => $this->Translate('Live'),
                'noData'   => $this->Translate('No data - please configure variables and make sure their values are > 0.'),
                'incoming' => $this->Translate('Incoming'),
                'outgoing' => $this->Translate('Outgoing'),
            ],
        ];
    }
}
