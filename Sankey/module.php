<?php

declare(strict_types=1);

class Sankey extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Title', 'Energiefluss');
        $this->RegisterPropertyInteger('ColorTitle', 0x1e293b);
        $this->RegisterPropertyInteger('ColorLabel', 0x1e293b);
        $this->RegisterPropertyString('Links', '[]');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        // Alle bisherigen Nachrichten abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Auf Änderungen aller konfigurierten Variablen reagieren
        $links = json_decode($this->ReadPropertyString('Links'), true) ?? [];
        foreach ($links as $link) {
            $varID = intval($link['VariableID'] ?? 0);
            if ($varID > 0 && IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }

        $this->UpdateVisualizationValue($this->GetVisualizationTile());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message === VM_UPDATE) {
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

    // Liefert ['rows' => [...], 'nodeColors' => [...]]
    // rows-Format: [source, target, value, unit]
    private function CollectData(): array
    {
        $links        = json_decode($this->ReadPropertyString('Links'), true) ?? [];
        $rows         = [];
        $nodeColorMap = []; // node-name => hex

        $fallback = ['#2563eb','#16a34a','#ea580c','#7c3aed','#db2777',
                     '#ca8a04','#0891b2','#dc2626','#059669','#9333ea'];

        foreach ($links as $link) {
            $source = trim($link['Source'] ?? '');
            $target = trim($link['Target'] ?? '');
            $varID  = intval($link['VariableID'] ?? 0);

            if ($source === '' || $target === '' || $varID <= 0 || !IPS_VariableExists($varID)) {
                continue;
            }

            $value = floatval(GetValue($varID));
            if ($value <= 0) {
                continue;
            }

            $unit = $this->GetVariableUnit($varID);

            $rows[] = [
                htmlspecialchars($source, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($target, ENT_QUOTES, 'UTF-8'),
                $value,
                $unit,
            ];

            // Farbe der Quell-Node speichern (erste Definition gewinnt)
            $color = intval($link['Color'] ?? 0);
            if ($color > 0 && !array_key_exists($source, $nodeColorMap)) {
                $nodeColorMap[$source] = sprintf('#%06x', $color);
            }
        }

        return [
            'rows'       => $rows,
            'nodeColors' => $nodeColorMap,
        ];
    }

    private function GenerateHTML(): string
    {
        $title       = htmlspecialchars($this->ReadPropertyString('Title'), ENT_QUOTES, 'UTF-8');
        $colorTitle  = sprintf('#%06x', $this->ReadPropertyInteger('ColorTitle'));
        $colorLabel  = sprintf('#%06x', $this->ReadPropertyInteger('ColorLabel'));
        $data        = $this->CollectData();

        return $this->GenerateLocalHTML($title, $colorTitle, $colorLabel, $data);
    }

    private function GenerateLocalHTML(string $title, string $colorTitle, string $colorLabel, array $data): string
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
  h1 { text-align:center; color:{$colorTitle}; font-size:1.2em; font-weight:600; margin-bottom:10px; }
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

    h1 {
    flex:0 0 auto;
    text-align:center;
    color:{$colorTitle};
    font-size:1.2em;
    font-weight:600;
    margin:8px 0;
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
<h1>{$title}</h1>
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
            labelColor: '{$colorLabel}'
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