<?php

declare(strict_types=1);

namespace AssetGrabber\Services;

use Aura\Sql\ExtendedPdoInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;
use Symfony\Component\Process\Process;

class PluginListService
{
    /** @var array<string, string[]> */
    private array $revisionData = [];

    private int $prevRevision = 0;

    /**
     * @var array<string, string[]>
     */
    private array $currentRevision = [];

    /** @var array <string, string[]> */
    private array $oldPluginData = [];

    /**
     * @param string[]|null $filter
     * @return array<string, string[]>
     */

    /**
     * @param array<int, string> $userAgents
     */
    public function __construct(private array $userAgents, private ExtendedPdoInterface $pdo)
    {
        shuffle($this->userAgents);
        $this->loadRevisionData();
    }

    /**
     * @param  array<int, string>|null  $filter
     * * @return array<string, string[]>
     * @return \string[][]
     */
    public function getPluginListForAction(?array $filter, string $action): array
    {
        $lastRevision = 0;
        if (isset($this->revisionData[$action])) {
            $lastRevision = $this->revisionData[$action]['revision'];
            return $this->filter($this->getPluginsToUpdate([], $lastRevision, $action), $filter);
        }


        return $this->filter($this->pullWholePluginList(), $filter);



    }

    /**
     * @param array<int, string>|null $filter
     * @return array<string, string[]>
     * @deprecated Use getPluginListForAction instead
     */
    public function getPluginList(?array $filter = []): array
    {
        if (! $filter) {
            $filter = [];
        }

        if (file_exists('/opt/assetgrabber/data/plugin-data.json')) {
            $json                = file_get_contents('/opt/assetgrabber/data/plugin-data.json');
            $this->oldPluginData = json_decode($json, true);
            $this->prevRevision  = $this->oldPluginData['meta']['my_revision'];
            return $this->filter($this->getPluginsToUpdate($filter, $this->prevRevision, $action), $filter);
        }

        $pluginList = $this->pullWholePluginList();
        return $this->filter($pluginList, $filter);
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    public function getPluginMetadata(string $plugin): array
    {
        if (! file_exists('/opt/assetgrabber/data/plugin-raw-data')) {
            mkdir('/opt/assetgrabber/data/plugin-raw-data');
        }

        if (file_exists('/opt/assetgrabber/data/plugin-raw-data/' . $plugin . '.json') && filemtime('/opt/assetgrabber/data/plugin-raw-data/' . $plugin . '.json') > time() - 86400) {
            $json = file_get_contents('/opt/assetgrabber/data/plugin-raw-data/' . $plugin . '.json');
            return json_decode($json, true);
        } else {
            $url    = 'https://api.wordpress.org/plugins/info/1.0/' . $plugin . '.json';
            $client = new Client();
            try {
                $response = $client->get($url);
                $data     = json_decode($response->getBody()->getContents(), true);
                file_put_contents(
                    '/opt/assetgrabber/data/plugin-raw-data/' . $plugin . '.json',
                    json_encode($data, JSON_PRETTY_PRINT)
                );
                return $data;
            } catch (ClientException $e) {
                if ($e->getCode() === 404) {
                    $content = $e->getResponse()->getBody()->getContents();
                    file_put_contents('/opt/assetgrabber/data/plugin-raw-data/' . $plugin . '.json', $content);
                    return json_decode($content, true);
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    public function getVersionsForPlugin(string $plugin): array
    {
        $data = $this->getPluginMetadata($plugin);

        if (isset($data['versions'])) {
            $pluginData = $data['versions'];
        } elseif (isset($data['version'])) {
            $pluginData = [$data['version'] => $data['download_link']];
        } else {
            return [];
        }

        if (isset($pluginData['trunk'])) {
            unset($pluginData['trunk']);
        }

        return $pluginData;
    }

    public function identifyCurrentRevision(bool $force = false): int
    {
        if (! $force && file_exists('/opt/assetgrabber/data/raw-changelog') && filemtime('/opt/assetgrabber/data/raw-changelog') > time() - 86400) {
            $output = file_get_contents('/opt/assetgrabber/data/raw-changelog');
        } else {
            $command = [
                'svn',
                'log',
                '-v',
                '-q',
                'https://plugins.svn.wordpress.org',
                "-r",
                "HEAD",
            ];

            $process = new Process($command);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Unable to get list of plugins to update' . $process->getErrorOutput());
            }

            $output = $process->getOutput();
            file_put_contents('/opt/assetgrabber/data/raw-changelog', $output);
        }

        $output = explode(PHP_EOL, $output);
        preg_match('/([0-9]+) \|/', $output[1], $matches);
        $this->prevRevision = (int) $matches[1];
        return (int) $matches[1];
    }

    /**
     * @return array<string, string[]>
     */
    private function pullWholePluginList(): array
    {
        if (file_exists('/opt/assetgrabber/data/raw-svn-plugin-list') && filemtime('/opt/assetgrabber/data/raw-svn-plugin-list') > time() - 86400) {
            $plugins = file_get_contents('/opt/assetgrabber/data/raw-svn-plugin-list');
        } else {
            try {
                $client   = new Client();
                $plugins  = $client->get('https://plugins.svn.wordpress.org/', ['headers' => ['AssetGrabber']]);
                $contents = $plugins->getBody()->getContents();
                file_put_contents('/opt/assetgrabber/data/raw-svn-plugin-list', $contents);
                $plugins = $contents;
            } catch (ClientException $e) {
                throw new RuntimeException('Unable to download plugin list: ' . $e->getMessage());
            }
        }
        preg_match_all('#<li><a href="([^/]+)/">([^/]+)/</a></li>#', $plugins, $matches);
        $plugins = $matches[1];

        $pluginsToReturn = [];
        foreach ($plugins as $plugin) {
            $pluginsToReturn[$plugin] = [];
        }

        file_put_contents('/opt/assetgrabber/data/raw-plugin-list', implode(PHP_EOL, $plugins));

        return $pluginsToReturn;
    }

    /**
     * @param array<int, string> $explicitlyRequested
     * @return array<string, string[]>
     */
    private function getPluginsToUpdate(?array $explicitlyRequested, string $lastRevision, string $action = 'default'): array
    {
        $lastRev    = (int) $lastRevision;
        $targetRev  = $lastRev + 1;
        $currentRev = 'HEAD';

        if ($this->currentRevision === $this->prevRevision) {
            return $this->mergePluginsToUpdate([], $explicitlyRequested);
        }


        $command = [
            'svn',
            'log',
            '-v',
            '-q',
            'https://plugins.svn.wordpress.org',
            "-r",
            "$targetRev:$currentRev",
        ];

        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Unable to get list of plugins to update' . $process->getErrorOutput());
        }

        $output = explode(PHP_EOL, $process->getOutput());

        $pluginsToUpdate = [];
        foreach ($output as $line) {
            preg_match('#^   [ADMR] /([A-z]+)/#', $line, $matches);
            if ($matches) {
                $plugin = trim($matches[1]);
            }

            preg_match('#^r([0-9]+) \|#', $line, $matches);
            if ($matches) {
                $revision = (int) $matches[1];
            }
        }

        var_dump($pluginsToUpdate); die;
        $this->currentRevision[$action] = ['revision' => $revision];
        //$pluginsToUpdate = $this->mergePluginsToUpdate($pluginsToUpdate, $explicitlyRequested);

        return $pluginsToUpdate;
    }

    /**
     * @param array<string, string[]> $pluginsToUpdate
     * @param array<int, string> $explicitlyRequested
     * @return array<string, string[]>
     */
    private function mergePluginsToUpdate(array $pluginsToUpdate = [], array $explicitlyRequested = []): array
    {
        $allPlugins = $this->pullWholePluginList();

        foreach ($allPlugins as $pluginName => $pluginVersions) {
            // Is this the first time we've seen the plugin?
            if (! isset($this->oldPluginData['plugins'][$pluginName])) {
                $pluginsToUpdate[$pluginName] = [];
            }

            if (in_array($pluginName, $explicitlyRequested)) {
                $pluginsToUpdate[$pluginName] = [];
            }
        }

        return $pluginsToUpdate;
    }

    /**
     * @param array<string, string[]> $plugins
     */
    public function preservePluginList(array $plugins): int|bool
    {
        if ($this->oldPluginData) {
            $toSave                        = [
                'meta'    => [],
                'plugins' => $this->oldPluginData['plugins'],
            ];
            $toSave['plugins']             = array_merge($toSave['plugins'], $plugins);
            $toSave['meta']['my_revision'] = $this->currentRevision;
        } else {
            $toSave = [
                'meta'    => [
                    'my_revision' => $this->currentRevision,
                ],
                'plugins' => [],
            ];

            $toSave['plugins'] = array_merge($toSave['plugins'], $plugins);
        }

        return file_put_contents('/opt/assetgrabber/data/plugin-data.json', json_encode($toSave, JSON_PRETTY_PRINT));
    }

    /**
     * Reduces the plugins slated for update to only those specified in the filter.
     *
     * @param  array<string, string[]>  $plugins
     * @param  array<int, string>|null  $filter
     * @return array<string, string[]>
     */
    private function filter(array $plugins, ?array $filter): array
    {
        if (! $filter) {
            return $plugins;
        }

        $filtered = [];
        foreach ($filter as $plugin) {
            if (array_key_exists($plugin, $plugins)) {
                $filtered[$plugin] = $plugins[$plugin];
            }
        }

        return $filtered;
    }

    public function preserveRevision(string $action): void
    {
        $data = [
            'id' => $this->revisionData[$action]['id'] ?? null,
            'action'   => $action,
            'revision' => $this->currentRevision[$action]['revision'],
        ];

        if ($data['null'] === null) {
            $sql = 'INSERT INTO revisions (action, revision) VALUES (:action, :revision)';
            unset($data['id']);
        } else {
            $sql = 'UPDATE revisions SET revision = :revision WHERE id = :id';
            unset($data['action']);
        }

        $this->pdo->perform($sql, $data);
    }

    public function loadRevisionData()
    {
        $revisions = $this->pdo->fetchAll('SELECT * FROM revisions');
        foreach ($revisions as $revision) {
            $this->revisionData[$revision['action']] = ['id' => $revision['id'], 'revision' => $revision['revision']];
        }
    }
}
