<?php

declare(strict_types=1);

namespace Symplify\MonorepoSplit;

use Symplify\MonorepoSplit\Exception\ConfigurationException;

final class PublicAccessTokenResolver
{
    /**
     * @var string
     */
    private const PAT = 'PAT';

    /**
     * @var string
     */
    private const GITHUB_TOKEN = 'GITHUB_TOKEN';

    /**
     * @var string
     */
    private const GITLAB_TOKEN = 'GITLAB_TOKEN';

    /**
     * @param array<string, string> $env
     */
    public function resolve(array $env): string
    {
        // An unavailable secret resolves to an empty string, so treat empty values as missing.
        foreach ([self::PAT, self::GITHUB_TOKEN] as $name) {
            if (($env[$name] ?? '') !== '') {
                return $env[$name];
            }
        }

        if (($env[self::GITLAB_TOKEN] ?? '') !== '') {
            return 'oauth2:' . $env[self::GITLAB_TOKEN];
        }

        $message = 'Access token is missing or empty. Set PAT (or GITHUB_TOKEN) or GITLAB_TOKEN in the env of this step. '
            . 'Secrets are not available to workflows triggered from forks.';

        throw new ConfigurationException($message);
    }
}
