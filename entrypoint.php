<?php

declare(strict_types=1);

use Symplify\MonorepoSplit\Config;
use Symplify\MonorepoSplit\ConfigFactory;
use Symplify\MonorepoSplit\Exception\ConfigurationException;

require_once __DIR__ . '/src/autoload.php';

note('Resolving configuration...');

$configFactory = new ConfigFactory();
try {
    $config = $configFactory->create(getenv());
} catch (ConfigurationException $configurationException) {
    error($configurationException->getMessage());
    exit(1);
}

exec('git config --system --add safe.directory /github/workspace');
exec('git config --system --add safe.directory /tmp/monorepo_split/build_directory');

validateInputs($config);
setupGitCredentials($config);
setupGitAuthentication($config);


$cloneDirectory = sys_get_temp_dir() . '/monorepo_split/clone_directory';
$buildDirectory = sys_get_temp_dir() . '/monorepo_split/build_directory';

$hostRepositoryOrganizationName = $config->getGitRepository();

// info
$clonedRepository = 'https://' . $hostRepositoryOrganizationName;
$cloningMessage = sprintf('Cloning "%s" repository to "%s" directory', $clonedRepository, $cloneDirectory);
note($cloningMessage);

// The access token is supplied through setupGitAuthentication(), never in the URL, so it cannot
// leak into logs, `.git/config`, or git's own error output.
$commandLine = 'git clone -- ' . escapeshellarg($clonedRepository) . ' ' . escapeshellarg($cloneDirectory);
exec_with_note($commandLine);

$baseDir = getcwd();

chdir($cloneDirectory);

exec_with_output_print('git fetch');

note(sprintf('Trying to checkout %s branch', $config->getBranch()));

// The clone fetched every remote branch, so check for the target branch locally.
exec(sprintf('git rev-parse --verify --quiet %s', escapeshellarg('refs/remotes/origin/' . $config->getBranch())), $unusedOutput, $branchExitCode);

if ($branchExitCode === 0) {
    // Explicit start point and "--" so a same-named tag or top-level path can't be picked instead of the branch.
    exec_with_output_print(sprintf('git checkout -B %1$s %2$s --', escapeshellarg($config->getBranch()), escapeshellarg('refs/remotes/origin/' . $config->getBranch())));
} else {
    note(sprintf('Creating branch "%s" as it doesn\'t exist', $config->getBranch()));

    exec_with_output_print(sprintf('git checkout -b %s', escapeshellarg($config->getBranch())));

    // An empty repository has no commit to push yet; the split commit below creates the branch remotely.
    exec('git rev-parse --verify --quiet HEAD', $unusedOutput, $headExitCode);
    if ($headExitCode === 0) {
        exec_with_output_print('git push --quiet origin ' . escapeshellarg('HEAD:refs/heads/' . $config->getBranch()));
    }
}

chdir($baseDir);

note('Cleaning destination repository of old files');
// We're only interested in the .git directory, move it to $TARGET_DIR and use it from now on
mkdir($buildDirectory . '/.git', 0777, true);

execOrDie(sprintf('cp -r %s %s', escapeshellarg($cloneDirectory . '/.git'), escapeshellarg($buildDirectory)));


// cleanup old unused data to avoid pushing them
execOrDie('rm -rf ' . escapeshellarg($cloneDirectory));
// exec('rm -rf .git');


// copy the package directory including all hidden files to the clone dir
// make sure the source dir ends with `/.` so that all contents are copied (including .github etc)
$copyMessage = sprintf('Copying contents to git repo of "%s" branch from "%s" to "%s"', $config->getCommitHash(), $config->getPackageDirectory() . '/.', $buildDirectory);
note($copyMessage);
$commandLine = sprintf('cp -rav -- %s %s', escapeshellarg($config->getPackageDirectory() . '/.'), escapeshellarg($buildDirectory));
exec_with_output_print($commandLine);

note('Files that will be pushed');
list_directory_files($buildDirectory);


// WARNING! this function happen before we change directory
// if we do this in split repository, the original hash is missing there and it will fail
$commitMessage = createCommitMessage($config->getCommitHash());


$formerWorkingDirectory = getcwd();
chdir($buildDirectory);

$restoreChdirMessage = sprintf('Changing directory from "%s" to "%s"', $formerWorkingDirectory, $buildDirectory);
note($restoreChdirMessage);



// avoids doing the git commit failing if there are no changes to be commit, see https://stackoverflow.com/a/8123841/1348344
exec_with_output_print('git status');

// "status --porcelain" retrieves all modified files, no matter if they are newly created or not,
// when "diff-index --quiet HEAD" only checks files that were already present in the project.
execOrDie('git status --porcelain', $changedFiles);

// $changedFiles is an array that contains the list of modified files, and is empty if there are no changes.

if ($changedFiles) {
    note('Adding git commit');

    exec_with_output_print('git add .');

    $message = sprintf('Pushing git commit with "%s" message to "%s"', $commitMessage, $config->getBranch());
    note($message);

    execOrDie('git commit --message ' . escapeshellarg($commitMessage));
    execOrDie('git push --quiet origin ' . escapeshellarg('HEAD:refs/heads/' . $config->getBranch()));
} else {
    note('No files to change');
}


// push tag if present
if ($config->getTag()) {
    $message = sprintf('Publishing "%s"', $config->getTag());
    note($message);

    $commandLine = sprintf('git tag %s -m %s', escapeshellarg($config->getTag()), escapeshellarg($message));
    exec_with_note($commandLine);

    exec_with_note('git push --quiet origin ' . escapeshellarg('refs/tags/' . $config->getTag() . ':refs/tags/' . $config->getTag()));
}


// restore original directory to avoid nesting WTFs
chdir($formerWorkingDirectory);
$chdirMessage = sprintf('Changing directory from "%s" to "%s"', $buildDirectory, $formerWorkingDirectory);
note($chdirMessage);


function createCommitMessage(string $commitSha): string
{
    execOrDie('git show -s --format=%B ' . escapeshellarg($commitSha) . ' --', $outputLines);
    return $outputLines[0] ?? '';
}


function note(string $message): void
{
    echo PHP_EOL . PHP_EOL . "\033[0;33m[NOTE] " . $message . "\033[0m" . PHP_EOL . PHP_EOL;
}

function error(string $message): void
{
    echo PHP_EOL . PHP_EOL . "\033[0;31m[ERROR] " . $message . "\033[0m" . PHP_EOL . PHP_EOL;
}




function list_directory_files(string $directory): void
{
    exec_with_output_print('ls -la ' . escapeshellarg($directory));
}


/********************* helper functions *********************/

function execOrDie(string $command, ?array &$output = []): string|false
{
    $result = exec($command, $output, $errorCode);
    if (0 === $errorCode) {
        return $result;
    }

    echo implode(PHP_EOL, $output);
    exit(1);
}

function exec_with_note(string $commandLine): void
{
    note('Running: ' . $commandLine);
    execOrDie($commandLine);
}


function exec_with_output_print(string $commandLine): void
{
    execOrDie($commandLine, $outputLines);
    echo implode(PHP_EOL, $outputLines);
}


function setupGitCredentials(Config $config): void
{
    if ($config->getUserName()) {
        execOrDie('git config --global user.name ' . escapeshellarg($config->getUserName()));
    }

    if ($config->getUserEmail()) {
        execOrDie('git config --global user.email ' . escapeshellarg($config->getUserEmail()));
    }
}


/**
 * Authenticate git over HTTPS with an Authorization header instead of embedding the token in the remote URL.
 *
 * The header is passed through GIT_CONFIG_* environment variables (git >= 2.31), which every git
 * subprocess inherits. It is never written to disk or placed on a command line, and it is scoped to the
 * target repository URL. Git redacts it in GIT_TRACE_CURL output; the trace settings that would print it
 * anyway are cleared below.
 */
function setupGitAuthentication(Config $config): void
{
    foreach (['GIT_TRACE_REDACT', 'GIT_TRACE2_CONFIG_PARAMS', 'GIT_TRACE2_ENV_VARS'] as $variable) {
        putenv($variable);
    }

    $accessToken = $config->getAccessToken();

    // Tokens containing ":" are used as "user:password" as-is: GitLab tokens arrive as "oauth2:<token>", and a
    // user-supplied "user:token" keeps working as it did in the URL. GitHub accepts any username with a token.
    $credentials = str_contains($accessToken, ':') ? $accessToken : 'x-access-token:' . $accessToken;

    $index = (int) (getenv('GIT_CONFIG_COUNT') ?: 0);

    putenv(sprintf('GIT_CONFIG_KEY_%d=http.https://%s.extraheader', $index, $config->getGitRepository()));
    putenv(sprintf('GIT_CONFIG_VALUE_%d=AUTHORIZATION: basic %s', $index, base64_encode($credentials)));
    putenv(sprintf('GIT_CONFIG_COUNT=%d', $index + 1));
}


/**
 * Inputs are interpolated into git commands. They are shell-escaped everywhere; branch, tag, commit hash and
 * package directory are also validated here so they can't be read as git options or unexpanded shell variables.
 */
function validateInputs(Config $config): void
{
    $fail = static function (string $message): void {
        error($message);
        exit(1);
    };

    $values = [
        'branch' => $config->getBranch(),
        'tag' => $config->getTag(),
        'commit_hash' => $config->getCommitHash(),
    ];

    foreach ($values as $input => $value) {
        if ($value !== null && str_contains($value, '$')) {
            $fail(sprintf(
                'Input "%s" contains an unexpanded shell variable: "%s". Inputs are no longer shell-expanded; '
                . 'use a GitHub expression such as ${{ github.ref_name }} or ${{ github.sha }} instead.',
                $input,
                $value
            ));
        }
    }

    $branch = $config->getBranch();
    exec(sprintf('git check-ref-format --branch %s 2>/dev/null', escapeshellarg($branch)), $unusedOutput, $exitCode);
    if ($exitCode !== 0 || str_starts_with($branch, '-') || str_starts_with($branch, 'refs/') || $branch === '@') {
        $fail(sprintf('Invalid branch name "%s"', $branch));
    }

    $tag = $config->getTag();
    if ($tag !== null) {
        exec(sprintf('git check-ref-format %s 2>/dev/null', escapeshellarg('refs/tags/' . $tag)), $unusedOutput, $exitCode);
        if ($exitCode !== 0 || str_starts_with($tag, '-') || str_starts_with($tag, 'refs/')) {
            $fail(sprintf('Invalid tag name "%s"', $tag));
        }
    }

    if (str_starts_with($config->getCommitHash(), '-')) {
        $fail(sprintf('Invalid commit hash "%s"', $config->getCommitHash()));
    }

    if (str_starts_with($config->getPackageDirectory(), '-')) {
        $fail(sprintf('Invalid package directory "%s"', $config->getPackageDirectory()));
    }
}
