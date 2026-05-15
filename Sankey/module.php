<?php

declare(strict_types=1);

class Sankey extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Title', 'Energiefluss');
        $this->RegisterPropertyInteger('Height', 500);
        $this->RegisterPropertyString('Library', 'local');
        $this->RegisterPropertyInteger('ColorTitle', 0x1e293b);
        $this->RegisterPropertyInteger('ColorLabel', 0x1e293b);
        $this->RegisterPropertyString('Links', '[]');

        $this->RegisterVariableString('Diagram', 'Sankey-Diagramm', '~HTMLBox');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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

        $this->UpdateDiagram();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message === VM_UPDATE) {
            $this->UpdateDiagram();
        }
    }

    public function UpdateDiagram(): void
    {
        $this->SetValue('Diagram', $this->GenerateHTML());
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

    // Liefert ['rows' => [...], 'nodeColors' => [...], 'palette' => [...]]
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

        // Geordnete Farbpalette für Google Charts (Array nach Node-Reihenfolge)
        $orderedNodes = [];
        foreach ($rows as $row) {
            foreach ([$row[0], $row[1]] as $node) {
                if (!in_array($node, $orderedNodes, true)) {
                    $orderedNodes[] = $node;
                }
            }
        }
        $fallbackIdx = 0;
        $palette     = [];
        foreach ($orderedNodes as $node) {
            $palette[] = $nodeColorMap[$node] ?? $fallback[$fallbackIdx++ % count($fallback)];
        }

        return [
            'rows'       => $rows,
            'nodeColors' => $nodeColorMap,
            'palette'    => $palette,
        ];
    }

    private function GenerateHTML(): string
    {
        $title       = htmlspecialchars($this->ReadPropertyString('Title'), ENT_QUOTES, 'UTF-8');
        $height      = intval($this->ReadPropertyInteger('Height'));
        $colorTitle  = sprintf('#%06x', $this->ReadPropertyInteger('ColorTitle'));
        $colorLabel  = sprintf('#%06x', $this->ReadPropertyInteger('ColorLabel'));
        $data        = $this->CollectData();

        if ($this->ReadPropertyString('Library') === 'google') {
            return $this->GenerateGoogleChartsHTML($title, $height, $colorTitle, $colorLabel, $data);
        }
        return $this->GenerateLocalHTML($title, $height, $colorTitle, $colorLabel, $data);
    }

    private function GenerateLocalHTML(string $title, int $height, string $colorTitle, string $colorLabel, array $data): string
    {
        $jsRows   = json_encode($data['rows'],       JSON_UNESCAPED_UNICODE);
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
  #chart_div { width:100%; height:{$height}px; }
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
    drawSankey('chart_div', rows, { nodeColors: nodeColors, linkOpacity: 0.45, labelColor: '{$colorLabel}' });
  }
  render();
  var rTimer;
  window.addEventListener('resize', function() { clearTimeout(rTimer); rTimer = setTimeout(render, 250); });
</script>
</body>
</html>
HTML;
    }

    private function GenerateGoogleChartsHTML(string $title, int $height, string $colorTitle, string $colorLabel, array $data): string
    {
        $jsRows    = json_encode($data['rows'],    JSON_UNESCAPED_UNICODE);
        $jsPalette = json_encode($data['palette'], JSON_UNESCAPED_UNICODE);
        $noDataMsg = empty($data['rows'])
            ? '<p class="msg">Keine Daten &ndash; bitte Variablen konfigurieren und sicherstellen, dass deren Werte &gt;&nbsp;0 sind.</p>'
            : '';
        $chartDisplay = empty($data['rows']) ? 'none' : 'block';

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<script src="https://www.gstatic.com/charts/loader.js"></script>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:transparent; font-family:'Segoe UI',Arial,sans-serif; padding:12px; }
  h1 { text-align:center; color:{$colorTitle}; font-size:1.2em; font-weight:600; margin-bottom:10px; }
  #wrap { width:100%; height:{$height}px; display:flex; align-items:center; justify-content:center; }
  #chart_div { width:100%; height:100%; display:{$chartDisplay}; }
  .msg { color:#64748b; font-size:.9em; text-align:center; }
</style>
</head>
<body>
<h1>{$title}</h1>
<div id="wrap">
  {$noDataMsg}
  <div id="chart_div"></div>
</div>
<script>
google.charts.load('current', {packages:['sankey']});
google.charts.setOnLoadCallback(function() {
  var rows = {$jsRows};
  if (!rows.length) return;
  var dt = new google.visualization.DataTable();
  dt.addColumn('string','Von');
  dt.addColumn('string','Nach');
  dt.addColumn('number','Wert');
  dt.addColumn({type:'string', role:'tooltip', p:{html:true}});
  dt.addRows(rows.map(function(r) {
    var unit = r[3] ? ' ' + r[3] : '';
    var val  = r[2].toLocaleString('de-DE', {maximumFractionDigits: 2});
    return [
      r[0], r[1], r[2],
      '<div style="padding:6px 10px;font-family:Segoe UI,Arial,sans-serif;font-size:12px;line-height:1.5;color:#1e293b;background:#fff">'
        + '<b>' + r[0] + '</b> &rarr; <b>' + r[1] + '</b><br>'
        + val + unit
      + '</div>'
    ];
  }));
  var options = {
    width: '100%',
    height: {$height},
    tooltip: { isHtml: true },
    sankey: {
      node: {
        colors: {$jsPalette},
        label: { fontName: 'Segoe UI', fontSize: 13, color: '{$colorLabel}' },
        interactivity: true,
        width: 22,
        nodePadding: 14
      },
      link: { colorMode: 'source', fillOpacity: 0.45 }
    }
  };
  var chart = new google.visualization.Sankey(document.getElementById('chart_div'));
  chart.draw(dt, options);
});
</script>
</body>
</html>
HTML;
    }
}
