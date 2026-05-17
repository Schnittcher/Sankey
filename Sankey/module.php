<?php

declare(strict_types=1);

class Sankey extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorLabel', 0x1e293b);
        $this->RegisterPropertyBoolean('ShowValues', false);
        $this->RegisterPropertyBoolean('StaticMode', false);
        $this->RegisterPropertyString('Links', '[]');

        $this->RegisterVariableInteger('StartDate', 'Startdatum', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('EndDate',   'Enddatum',   '~UnixTimestamp', 11);
        $this->EnableAction('StartDate');
        $this->EnableAction('EndDate');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        $staticMode = $this->ReadPropertyBoolean('StaticMode');

        // Datumsvariablen nur im statischen Modus sichtbar und bedienbar
        IPS_SetHidden($this->GetIDForIdent('StartDate'),   !$staticMode);
        IPS_SetHidden($this->GetIDForIdent('EndDate'),     !$staticMode);
        IPS_SetDisabled($this->GetIDForIdent('StartDate'), !$staticMode);
        IPS_SetDisabled($this->GetIDForIdent('EndDate'),   !$staticMode);

        // Standardzeitraum setzen wenn noch kein Wert vorhanden
        if ($staticMode && GetValue($this->GetIDForIdent('StartDate')) === 0) {
            $now = time();
            SetValue($this->GetIDForIdent('StartDate'), mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now)));
            SetValue($this->GetIDForIdent('EndDate'), $now);
        }

        // Alle bisherigen Nachrichten abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Im Live-Modus Variablen überwachen
        if (!$staticMode) {
            $links = json_decode($this->ReadPropertyString('Links'), true) ?? [];
            foreach ($links as $link) {
                $varID = intval($link['VariableID'] ?? 0);
                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
        }

        $this->UpdateVisualizationValue($this->GetVisualizationTile());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateDiagram();
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'StartDate' || $Ident === 'EndDate') {
            SetValue($this->GetIDForIdent($Ident), $Value);
            $this->UpdateDiagram();
        }
    }

    public function UpdateDiagram(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->CollectData(), JSON_UNESCAPED_UNICODE));
    }

    public function GetVisualizationTile()
    {
        return $this->GenerateHTML();
    }

    // --- private ---

    private function GetVariableUnit(int $varID): string
    {
        // Neue Darstellungen (Symcon 6.x+) haben Vorrang
        if (function_exists('IPS_GetVariablePresentation')) {
            $pres = IPS_GetVariablePresentation($varID);
            if (!empty($pres['SUFFIX'])) {
                return $pres['SUFFIX'];
            }
        }

        // Klassisches Profil-System
        $varInfo     = IPS_GetVariable($varID);
        $profileName = $varInfo['VariableCustomProfile'] !== ''
            ? $varInfo['VariableCustomProfile']
            : $varInfo['VariableProfile'];

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return '';
        }
        return IPS_GetVariableProfile($profileName)['Suffix'];
    }


    private function GetValueFromArchive(int $varID, int $startTime, int $endTime): float
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        if ($archiveID === 0) {
            return 0.0;
        }

        if (!AC_GetLoggingStatus($archiveID, $varID)) {
            return 0.0;
        }

        // Aggregationstyp ermitteln: 0 = Standard, 1 = Zähler
        $isCounter = false;

        $isCounter = boolval(AC_GetAggregationType($archiveID, $varID)); // Cache füllen

        if ($isCounter) {
            // Letzten protokollierten Wert im Zeitraum verwenden
            $values = AC_GetLoggedValues($archiveID, $varID, $startTime, $endTime, 0);
            if (empty($values)) {
                return 0.0;
            }
            return floatval(end($values)['Value']);
        }

        // Standard: Durchschnitt über den Zeitraum
        $agg = AC_GetAggregatedValues($archiveID, $varID, $startTime, $endTime, 0);
        if (empty($agg)) {
            return 0.0;
        }
        return floatval($agg[0]['Avg']);
    }

    // Liefert ['rows' => [...], 'nodeColors' => [...]]
    // rows-Format: [source, target, value, unit]

    private function CollectData(): array
    {
        $links      = json_decode($this->ReadPropertyString('Links'), true) ?? [];
        $staticMode = $this->ReadPropertyBoolean('StaticMode');
        $rows         = [];
        $nodeColorMap = [];

        $startTime = $staticMode ? (int) GetValue($this->GetIDForIdent('StartDate')) : 0;
        $endTime   = $staticMode ? (int) GetValue($this->GetIDForIdent('EndDate'))   : 0;

        foreach ($links as $link) {
            $source = trim($link['Source'] ?? '');
            $target = trim($link['Target'] ?? '');
            $varID  = intval($link['VariableID'] ?? 0);

            if ($source === '' || $target === '' || $varID <= 0 || !IPS_VariableExists($varID)) {
                continue;
            }

            $value = $staticMode
                ? $this->GetValueFromArchive($varID, $startTime, $endTime)
                : floatval(GetValue($varID));

            $invert         = boolval($link['Invert'] ?? false);
            $ignoreNegative = boolval($link['IgnoreNegative'] ?? false);

            if ($invert) {
                $value *= -1;
            }

            if ($value < 0) {
                if ($ignoreNegative) {
                    continue;
                }

                [$source, $target] = [$target, $source];
                $value = abs($value);
            }

            if ($value == 0.0) {
                continue;
            }

            if ($value < 0) {
                [$source, $target] = [$target, $source];
                $value = abs($value);
            }

            $unit = $this->GetVariableUnit($varID);

            $rows[] = [
                htmlspecialchars($source, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($target, ENT_QUOTES, 'UTF-8'),
                $value,
                $unit,
            ];

            $color = intval($link['Color'] ?? 0);

            if ($color > 0) {
                $hex = sprintf('#%06x', $color);

                if (!array_key_exists($source, $nodeColorMap)) {
                    $nodeColorMap[$source] = $hex;
                }

                if (!array_key_exists($target, $nodeColorMap)) {
                    $nodeColorMap[$target] = $hex;
                }
            }
        }

        return [
            'rows'       => $rows,
            'nodeColors' => $nodeColorMap,
        ];
    }

    private function GenerateHTML(): string
    {
        $colorLabel = sprintf('#%06x', $this->ReadPropertyInteger('ColorLabel'));
        $showValues = $this->ReadPropertyBoolean('ShowValues') ? 'true' : 'false';
        $data       = $this->CollectData();

        return $this->GenerateLocalHTML($colorLabel, $showValues, $data);
    }

    private function GenerateLocalHTML(string $colorLabel, string $showValues, array $data): string
    {
        $jsRows   = json_encode($data['rows'], JSON_UNESCAPED_UNICODE);
        $jsColors = json_encode($data['nodeColors'], JSON_UNESCAPED_UNICODE);
        $sankeyJS = file_get_contents(__DIR__ . '/libs/sankey.js');

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:transparent; font-family:'Segoe UI',Arial,sans-serif; padding:12px; }
  html, body {
    width:100%;
    height:100%;
    margin:0;
    padding:0;
    overflow:hidden;
    background:transparent;
    font-family:'Segoe UI',Arial,sans-serif;
    }

    body {
    display:flex;
    flex-direction:column;
    }

    #chart_div {
        flex:1 1 auto;
        width:100%;
        min-height:120px;
        padding-bottom:18px;
        opacity:1;
        transition:opacity .25s ease;
    }
</style>
</head>
<body>
<div id="chart_div"></div>
<script>{$sankeyJS}</script>
<script>
    var rows = {$jsRows};
    var nodeColors = {$jsColors};

    function render() {
        var chart = document.getElementById('chart_div');
        chart.innerHTML = '';

        drawSankey('chart_div', rows, {
            nodeColors: nodeColors,
            linkOpacity: 0.45,
            labelColor: '{$colorLabel}',
            showValues: {$showValues}
        });
    }

    function handleMessage(message) {
        var data = typeof message === 'string' ? JSON.parse(message) : message;

        rows = data.rows || [];
        nodeColors = data.nodeColors || {};

        render();
    }

    window.addEventListener('message', function(event) {
        handleMessage(event.data);
    });

    render();

    var resizeTimer;

    if (window.ResizeObserver) {
        var observer = new ResizeObserver(function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(render, 150);
        });

        observer.observe(document.body);

    } else {

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(render, 150);
        });

    }
</script>
</body>
</html>
HTML;
    }
}
