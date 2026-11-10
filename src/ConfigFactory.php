<?php

declare(strict_types=1);

namespace Symplify\MonorepoSplit;

use Symplify\MonorepoSplit\Exception\ConfigurationException;

final class ConfigFactory
{
    /**
     * @var string
     */
    private const GITLAB = 'GITLAB';

    /**
     * @var string
     */
    private const GITHUB = 'GITHUB';

    private PublicAccessTokenResolver $publicAccessTokenResolver;

    public function __construct()
    {
        $this->publicAccessTokenResolver = new PublicAccessTokenResolver();
    }

    /**
     * @param array<string, mixed> $env
     */
    public function create(array $env): Config
    {
        $ciPlatform = $this->resolvePlatform($env);
        $accessToken = $this->publicAccessTokenResolver->resolve($env);
        $commitHash = $this->resolveCommitHash($ciPlatform, $env);

        return $this->createFromEnv($env, $accessToken, $commitHash, $ciPlatform);
    }

    /**
     * @param array<string, mixed> $env
     */
    private function resolvePlatform(array $env): string
    {
        return isset($env['GITLAB_CI']) ? self::GITLAB : self::GITHUB;
    }

    /**
     * @param array<string, mixed> $env
     */
    private function createFromEnv(array $env, string $accessToken, string $commitHash, string $ciPlatform): Config
    {
        $envPrefix = $ciPlatform === self::GITHUB ? 'INPUT_' : '';

        $required = function (string $name, string $label) use ($env, $envPrefix): string {
            return $this->input($env, $envPrefix . $name) ?? throw new ConfigurationException($label . ' is missing');
        };

        return new Config(
            packageDirectory: $required('PACKAGE_DIRECTORY', 'Package directory'),
            repositoryHost: $required('REPOSITORY_HOST', 'Repository host'),
            repositoryOrganization: $required('REPOSITORY_ORGANIZATION', 'Repository organization'),
            repositoryName: $required('REPOSITORY_NAME', 'Repository name'),
            // optional
            branch: $this->resolveGitHubRefShorthand($env, $this->input($env, $envPrefix . 'BRANCH')) ?? 'main',
            tag: $this->resolveGitHubRefShorthand($env, $this->input($env, $envPrefix . 'TAG')),
            userName: $this->input($env, $envPrefix . 'USER_NAME'),
            userEmail: $this->input($env, $envPrefix . 'USER_EMAIL'),
            // required
            commitHash: $this->resolveGitHubRefShorthand($env, $this->input($env, $envPrefix . 'COMMIT_HASH')) ?? $commitHash,
            accessToken: $accessToken
        );
    }

    /**
     * GitHub sets every declared input, using an empty string when it was not provided, so treat empty as missing.
     *
     * @param array<string, mixed> $env
     */
    private function input(array $env, string $name): ?string
    {
        $value = $env[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $env
     */
    private function resolveCommitHash(string $ciPlatform, array $env): string
    {
        return $ciPlatform === self::GITLAB ? $env['CI_COMMIT_SHA'] : $env['GITHUB_SHA'];
    }

    /**
     * Earlier README examples and common workflows passed `${GITHUB_REF#refs/tags/}` or `${GITHUB_REF#refs/heads/}`
     * as inputs, which only worked because inputs were interpolated into shell commands unescaped. Inputs are now
     * escaped, so resolve those exact forms here. Any other unexpanded `$` form is rejected by the entrypoint.
     *
     * @param array<string, mixed> $env
     */
    private function resolveGitHubRefShorthand(array $env, ?string $value): ?string
    {
        $prefixes = [
            '${GITHUB_REF#refs/tags/}' => 'refs/tags/',
            '${GITHUB_REF#refs/heads/}' => 'refs/heads/',
        ];

        if ($value === null || ! isset($prefixes[$value])) {
            return $value;
        }

        $ref = (string) ($env['GITHUB_REF'] ?? '');
        $prefix = $prefixes[$value];

        return str_starts_with($ref, $prefix) ? substr($ref, strlen($prefix)) : $ref;
    }
}
