<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\WardServer;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Tests\TestCase;

/**
 * As skills são lidas por Claude Code, Codex e OpenCode: seguem o formato
 * aberto mais restrito dos três e só citam ferramentas que existem.
 */
class SkillsTest extends TestCase
{
    private function skills(): array
    {
        $dirs = glob(base_path('integrations/claude-code/ward-artifacts/skills/*'), GLOB_ONLYDIR);

        $this->assertNotEmpty($dirs);

        return $dirs;
    }

    /**
     * @return array<string, string>
     */
    private function frontmatter(string $dir): array
    {
        $text = file_get_contents("{$dir}/SKILL.md");

        $this->assertMatchesRegularExpression('/\A---\n(.*?)\n---\n/s', $text, basename($dir).': sem frontmatter');
        preg_match('/\A---\n(.*?)\n---\n/s', $text, $match);

        $fields = [];
        foreach (explode("\n", $match[1]) as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $fields[$key] = $value;
        }

        return $fields;
    }

    public function test_frontmatter_is_valid_for_every_client(): void
    {
        foreach ($this->skills() as $dir) {
            $fields = $this->frontmatter($dir);

            $this->assertSame(basename($dir), $fields['name'] ?? null, 'O name precisa ser igual ao diretório (OpenCode).');
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $fields['name']);
            $this->assertLessThanOrEqual(64, strlen($fields['name']));
            $this->assertNotEmpty($fields['description'] ?? null);
            $this->assertLessThanOrEqual(1024, mb_strlen($fields['description']));
        }
    }

    public function test_skills_only_mention_tools_that_exist(): void
    {
        $tools = collect((new ReflectionClass(WardServer::class))->getDefaultProperties()['tools'])
            ->map(fn (string $class) => app($class))
            ->map(fn (Tool $tool) => $tool->name())
            ->all();

        $known = '/`((?:get|publish|update|list|rename|invite|create|upsert|delete|save)_[a-z_]+|whoami)`/';

        foreach ($this->skills() as $dir) {
            preg_match_all($known, file_get_contents("{$dir}/SKILL.md"), $matches);

            foreach (array_unique($matches[1]) as $mentioned) {
                $this->assertContains($mentioned, $tools, basename($dir)." cita a ferramenta inexistente {$mentioned}.");
            }
        }
    }

    public function test_plugin_points_to_the_production_mcp_server(): void
    {
        $mcp = json_decode(file_get_contents(base_path('integrations/claude-code/ward-artifacts/.mcp.json')), true);

        $this->assertSame('http', $mcp['mcpServers']['ward-artifacts']['type']);
        $this->assertSame('https://artifacts.wardtecnology.com/mcp', $mcp['mcpServers']['ward-artifacts']['url']);
    }
}
