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

        // Beide Aggregationstypen: Durchschnitt über den Zeitraum
        $agg = AC_GetAggregatedValues($archiveID, $varID, 1, $startTime, $endTime, 0);
         
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

        if ($staticMode) {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
            foreach ($links as $link) {
                $varID = intval($link['VariableID'] ?? 0);
                if ($varID <= 0 || !IPS_VariableExists($varID)) {
                    continue;
                }
                if ($archiveID === 0 || !AC_GetLoggingStatus($archiveID, $varID)) {
                    $name = IPS_VariableExists($varID) ? IPS_GetName($varID) : "ID $varID";
                    $this->LogMessage("Sankey statisches Diagramm: Variable \"$name\" ($varID) wird nicht aufgezeichnet – Ausgabe abgebrochen.", KL_WARNING);
                    return ['rows' => [], 'nodeColors' => [], 'staticMode' => true, 'startTs' => $startTime, 'endTs' => $endTime];
                }
            }
        }

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
            'staticMode' => $staticMode,
            'startTs'    => $staticMode ? $startTime : null,
            'endTs'      => $staticMode ? $endTime   : null,
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
        $jsStatic = ($data['staticMode'] ?? false) ? 'true' : 'false';
        $jsStartTs = json_encode($data['startTs'] ?? null);
        $jsEndTs   = json_encode($data['endTs'] ?? null);
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

    #date_range {
        display:none;
        flex-shrink:0;
        align-items:center;
        justify-content:center;
        gap:8px;
        padding:5px 8px 3px;
    }

    .dp-input {
        background:rgba(255,255,255,0.07);
        border:1px solid rgba(255,255,255,0.13);
        border-radius:6px;
        padding:4px 8px;
        font-size:12px;
        font-family:'Segoe UI',Arial,sans-serif;
        color:#94a3b8;
        cursor:pointer;
        outline:none;
        color-scheme:dark;
    }

    .dp-input:hover {
        background:rgba(255,255,255,0.13);
        border-color:rgba(255,255,255,0.25);
    }

    .dp-sep {
        font-size:12px;
        color:#475569;
    }
</style>
</head>
<body>
<div id="chart_div"></div>
<div id="date_range">
    <input type="date" id="inp_start" class="dp-input">
    <span class="dp-sep">→</span>
    <input type="date" id="inp_end" class="dp-input">
</div>
<script>{$sankeyJS}</script>
<script>
    var rows       = {$jsRows};
    var nodeColors = {$jsColors};
    var staticMode = {$jsStatic};
    var startTs    = {$jsStartTs};
    var endTs      = {$jsEndTs};

    function tsToInputVal(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    function dateToTs(val) {
        if (!val) return 0;
        var p = val.split('-');
        return Math.floor(new Date(+p[0], +p[1] - 1, +p[2]).getTime() / 1000);
    }

    function updateDateRange() {
        var el = document.getElementById('date_range');
        if (!el) return;
        if (staticMode) {
            el.style.display = 'flex';
            document.getElementById('inp_start').value = tsToInputVal(startTs);
            document.getElementById('inp_end').value   = tsToInputVal(endTs);
        } else {
            el.style.display = 'none';
        }
    }

    function render() {
        var chart = document.getElementById('chart_div');
        chart.innerHTML = '';

        drawSankey('chart_div', rows, {
            nodeColors:  nodeColors,
            linkOpacity: 0.45,
            labelColor:  '{$colorLabel}',
            showValues:  {$showValues},
            staticMode:  staticMode
        });

        updateDateRange();
    }

    function handleMessage(message) {
        var data = typeof message === 'string' ? JSON.parse(message) : message;

        rows       = data.rows       || [];
        nodeColors = data.nodeColors || {};
        if (data.staticMode !== undefined) staticMode = data.staticMode;
        if (data.startTs    !== undefined) startTs    = data.startTs;
        if (data.endTs      !== undefined) endTs      = data.endTs;

        render();
    }

    document.getElementById('inp_start').addEventListener('change', function () {
        startTs = dateToTs(this.value);
        requestAction('StartDate', startTs);
    });

    document.getElementById('inp_end').addEventListener('change', function () {
        endTs = dateToTs(this.value);
        requestAction('EndDate', endTs);
    });

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
