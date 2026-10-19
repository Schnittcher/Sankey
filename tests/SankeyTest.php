<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SankeyTest extends TestCase
{
    private const MODULE_ID = '{4A7D5E9F-2B3C-4D8E-A1F6-9C0E3B7D2A58}';
    private const ARCHIVE_ID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const DATE_TIME_PRESENTATION = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const STATUS_ACTIVE = 102;

    protected function setUp(): void
    {
        // Jeder Test startet mit einem leeren Kernel und lädt die Bibliothek neu
        $this->resetKernel();
        parent::setUp();
    }

    // Die Konstanten für den Kachel-Typ gibt es erst ab Symcon 9.1. Die Stubs definieren sie nicht, der Test bildet also ein
    // System ohne sie nach (Symcon 9.0): Die Instanz muss sich trotzdem anlegen lassen.
    public function testInstanceCanBeCreatedOnSystemsWithoutVisualizationConstants(): void
    {
        $this->assertFalse(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN'));

        $instanceID = $this->sankey([]);

        $this->assertSame(self::STATUS_ACTIVE, IPS_GetInstance($instanceID)['InstanceStatus']);
    }

    // --- Variablen ---

    public function testDateVariablesUseNativePresentationAndAreRepeatable(): void
    {
        $instanceID = $this->sankey([]);
        IPS_ApplyChanges($instanceID);

        foreach (['StartDate', 'EndDate'] as $ident) {
            $id = IPS_GetObjectIDByIdent($ident, $instanceID);
            $presentation = IPS_GetVariable($id)['VariablePresentation'];

            $this->assertSame(self::DATE_TIME_PRESENTATION, $presentation['PRESENTATION']);
            $this->assertSame(1, $presentation['DATE']);
            $this->assertSame(1, $presentation['TIME']);
            $this->assertSame(1, IPS_GetVariable($id)['VariableType']);
        }

        $this->assertCount(2, IPS_GetChildrenIDs($instanceID));
    }

    public function testDateVariablesAreOnlyVisibleInStaticMode(): void
    {
        $instanceID = $this->sankey([], ['StaticMode' => false]);
        $startID = IPS_GetObjectIDByIdent('StartDate', $instanceID);
        $this->assertTrue(IPS_GetObject($startID)['ObjectIsHidden']);

        IPS_SetProperty($instanceID, 'StaticMode', true);
        IPS_ApplyChanges($instanceID);

        $this->assertFalse(IPS_GetObject($startID)['ObjectIsHidden']);
        $this->assertGreaterThan(0, GetValueInteger($startID));
        $this->assertGreaterThan(0, GetValueInteger(IPS_GetObjectIDByIdent('EndDate', $instanceID)));
    }

    // --- Live-Daten ---

    public function testLivePositiveValueFlowsFromSourceToTarget(): void
    {
        $instanceID = $this->sankey([$this->link('PV', 'Haus', $this->floatVar(4200.0))]);

        $data = $this->tileData($instanceID);

        $this->assertFalse($data['staticMode']);
        $this->assertEquals([['PV', 'Haus', 4200.0, '', '']], $data['rows']);
    }

    public function testLiveNegativeValueReversesDirection(): void
    {
        $instanceID = $this->sankey([$this->link('Netz', 'Haus', $this->floatVar(-800.0))]);

        $this->assertEquals([['Haus', 'Netz', 800.0, '', '']], $this->tileData($instanceID)['rows']);
    }

    public function testInvertTurnsTheDirectionAround(): void
    {
        $instanceID = $this->sankey([$this->link('Netz', 'Haus', $this->floatVar(-800.0), ['Invert' => true])]);

        $this->assertEquals([['Netz', 'Haus', 800.0, '', '']], $this->tileData($instanceID)['rows']);
    }

    public function testIgnoreNegativeDropsNegativeValuesAndZeroValuesAreSkipped(): void
    {
        $instanceID = $this->sankey([
            $this->link('Netz', 'Haus', $this->floatVar(-800.0), ['IgnoreNegative' => true]),
            $this->link('Leer', 'Haus', $this->floatVar(0.0))
        ]);

        $this->assertSame([], $this->tileData($instanceID)['rows']);
    }

    public function testIncompleteLinksAreSkipped(): void
    {
        $instanceID = $this->sankey([
            $this->link('', 'Haus', $this->floatVar(1.0)),
            $this->link('PV', '  ', $this->floatVar(1.0)),
            $this->link('PV', 'Haus', 0),
            $this->link('PV', 'Haus', 99999)
        ]);

        $this->assertSame([], $this->tileData($instanceID)['rows']);
    }

    // Namen werden unverändert übergeben (kein HTML-Escaping in PHP), die Farbe hängt am selben Namen
    public function testNodeNamesWithSpecialCharactersKeepTheirColor(): void
    {
        $instanceID = $this->sankey([$this->link('Wallbox & <E-Auto>', 'Haus', $this->floatVar(1.0), ['Color' => 0xFF0000])]);

        $data = $this->tileData($instanceID);

        $this->assertSame('Wallbox & <E-Auto>', $data['rows'][0][0]);
        $this->assertSame('#ff0000', $data['nodeColors']['Wallbox & <E-Auto>']);
        $this->assertSame('#ff0000', $data['nodeColors']['Haus']);
    }

    public function testLabelColorIsAutomaticByDefault(): void
    {
        $this->assertSame('', $this->tileData($this->sankey([]))['labelColor']);
    }

    public function testLabelColorCanBeFixed(): void
    {
        $this->assertSame('#1e293b', $this->tileData($this->sankey([], ['ColorLabel' => 0x1e293b]))['labelColor']);
    }

    // --- Kachel ---

    public function testTileScriptCannotBeBrokenOutOfByNodeNames(): void
    {
        $instanceID = $this->sankey([$this->link('</script><b>x', 'Haus', $this->floatVar(1.0))]);

        $html = IPS\InstanceManager::getInstanceInterface($instanceID)->GetVisualizationTile();
        $startData = substr($html, (int) strrpos($html, '<script>handleMessage('));

        $this->assertStringNotContainsString('</script><b>', $startData);
        $this->assertSame('</script><b>x', $this->tileData($instanceID)['rows'][0][0]);
    }

    public function testTileContainsNoFixedFontOrBodyReset(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../Sankey/module.html');

        $this->assertStringNotContainsString('Segoe', $html);
        $this->assertStringNotContainsString('body{margin', str_replace(' ', '', $html));
        $this->assertStringContainsString('--content-color', $html);
    }

    // --- Archiv ---

    public function testArchiveModeSumsCountersAndSplitsSignedValues(): void
    {
        $archiveID = IPS_CreateInstance(self::ARCHIVE_ID);
        $start = strtotime('2026-10-01 00:00:00');
        $end = strtotime('2026-10-04 00:00:00');

        $counterID = $this->loggedVar($archiveID, 1);
        $signedID = $this->loggedVar($archiveID, 0);
        $this->addDays($archiveID, $counterID, $start, [10.0, 20.0]);
        $this->addDays($archiveID, $signedID, $start, [100.0, -50.0]);

        $instanceID = $this->sankey([
            $this->link('Zaehler', 'Haus', $counterID),
            $this->link('Netz', 'Haus', $signedID)
        ], ['StaticMode' => true]);
        RequestAction(IPS_GetObjectIDByIdent('EndDate', $instanceID), $end);
        RequestAction(IPS_GetObjectIDByIdent('StartDate', $instanceID), $start);

        $data = $this->tileData($instanceID);

        $this->assertTrue($data['staticMode']);
        $this->assertSame($start, $data['startTs']);
        $this->assertSame($end, $data['endTs']);
        $this->assertEquals([
            ['Zaehler', 'Haus', 30.0, '', ''],
            ['Netz', 'Haus', 100.0, '', ''],
            ['Haus', 'Netz-', 50.0, '', '']
        ], $data['rows']);
    }

    public function testArchiveModeWithoutLoggingShowsNothing(): void
    {
        IPS_CreateInstance(self::ARCHIVE_ID);
        $instanceID = $this->sankey([$this->link('PV', 'Haus', $this->floatVar(5.0))], ['StaticMode' => true]);

        $this->assertSame([], $this->tileData($instanceID)['rows']);
    }

    // --- Zeitraum ---

    public function testInvalidDateRangeIsRejected(): void
    {
        $instanceID = $this->sankey([], ['StaticMode' => true]);
        $startID = IPS_GetObjectIDByIdent('StartDate', $instanceID);
        $endID = IPS_GetObjectIDByIdent('EndDate', $instanceID);
        $oldStart = GetValueInteger($startID);

        RequestAction($startID, GetValueInteger($endID) + 3600);
        $this->assertSame($oldStart, GetValueInteger($startID));

        RequestAction($startID, 0);
        $this->assertSame($oldStart, GetValueInteger($startID));

        RequestAction($endID, $oldStart + 3600);
        $this->assertSame($oldStart + 3600, GetValueInteger($endID));
    }

    // --- Hilfen ---

    private function resetKernel(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    private function sankey(array $links, array $configuration = []): int
    {
        $instanceID = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetConfiguration($instanceID, json_encode(array_merge(['Links' => json_encode($links)], $configuration)));
        IPS_ApplyChanges($instanceID);
        return $instanceID;
    }

    private function link(string $source, string $target, int $variableID, array $options = []): array
    {
        return array_merge([
            'Source'         => $source,
            'Target'         => $target,
            'VariableID'     => $variableID,
            'Invert'         => false,
            'IgnoreNegative' => false,
            'Color'          => 0,
            'InfoVariableID' => 0
        ], $options);
    }

    private function floatVar(float $value): int
    {
        $id = IPS_CreateVariable(2);
        SetValueFloat($id, $value);
        return $id;
    }

    // Die Startdaten der Kachel entsprechen den Daten, die UpdateVisualizationValue() sendet
    private function tileData(int $instanceID): array
    {
        $html = IPS\InstanceManager::getInstanceInterface($instanceID)->GetVisualizationTile();
        $this->assertSame(1, preg_match('/<script>handleMessage\((.*)\);<\/script>$/s', $html, $matches));
        return json_decode(json_decode($matches[1], true), true);
    }

    private function loggedVar(int $archiveID, int $aggregationType): int
    {
        $id = IPS_CreateVariable(2);
        AC_SetLoggingStatus($archiveID, $id, true);
        AC_SetAggregationType($archiveID, $id, $aggregationType);
        IPS_ApplyChanges($archiveID);
        return $id;
    }

    private function addDays(int $archiveID, int $variableID, int $start, array $averages): void
    {
        $data = [];
        foreach ($averages as $index => $avg) {
            $data[] = [
                'Avg'       => $avg,
                'Min'       => $avg,
                'Max'       => $avg,
                'Duration'  => 86400,
                'TimeStamp' => $start + $index * 86400
            ];
        }
        AC_StubsAddAggregatedValues($archiveID, $variableID, 1, $data);
    }
}
