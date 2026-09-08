<?php

/**
 * @package     plg_task_akeebacron
 * @author      Paweł Półtoraczyk <https://web-service.com.pl>
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\Task\Akeebacron\Extension;

// no direct access
\defined('_JEXEC') or die;

use Akeeba\Component\AkeebaBackup\Administrator\Helper\PushMessages;
use Akeeba\Engine\Factory as EngineFactory;
use Akeeba\Engine\Platform as EnginePlatform;
use Akeeba\Engine\Platform\Joomla as JoomlaEnginePlatform;
use Composer\CaBundle\CaBundle;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Runs Akeeba Backup profiles from Joomla's Scheduled Tasks.
 *
 * @since  1.0.0
 */
class Akeebacron extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    /**
     * Backup origin. Akeeba Backup shows this one as "Joomla Scheduled Tasks".
     *
     * @since  1.0.0
     */
    private const BACKUP_TAG = 'joomla';

    /**
     * A backup still marked as running this long after it started is considered dead rather than
     * resumable, and the next run starts a fresh one.
     *
     * @since  1.0.0
     */
    private const RESUME_WINDOW = 86400;

    /**
     * Path of the Akeeba Backup component, relative to which the engine is loaded.
     *
     * @since  1.0.0
     */
    private const COMPONENT_PATH = JPATH_ADMINISTRATOR . '/components/com_akeebabackup';

    /**
     * @var    array
     * @since  1.0.0
     */
    protected const TASKS_MAP = [
        'akeebacron.backup' => [
            'langConstPrefix' => 'PLG_TASK_AKEEBACRON_BACKUP',
            'form'            => 'akeebacron_backup',
            'method'          => 'takeBackup',
        ],
    ];

    /**
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * @inheritDoc
     *
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Takes, resumes or skips a backup with the configured Akeeba Backup profile.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event
     *
     * @return  integer  The exit code
     *
     * @since   1.0.0
     */
    protected function takeBackup(ExecuteTaskEvent $event): int
    {
        $params    = $event->getArgument('params');
        $profileId = max(1, (int) ($params->profile_id ?? 1));
        $timeLimit = max(0, (int) ($params->time_limit ?? 30));

        if (!ComponentHelper::isEnabled('com_akeebabackup')) {
            $this->logTask('Akeeba Backup is not installed or has been disabled.', 'error');

            return Status::KNOCKOUT;
        }

        // A missing autoloader would be an uncatchable fatal error in require_once below.
        if (!is_file(self::COMPONENT_PATH . '/vendor/autoload.php')) {
            $this->logTask('The Akeeba Backup installation is incomplete, its autoloader is missing.', 'error');

            return Status::KNOCKOUT;
        }

        try {
            // An unfinished backup of our own always takes precedence over the freshness check.
            $backupId = $this->getResumableBackupId();

            if ($backupId !== null) {
                $this->logTask(\sprintf('Akeeba Backup: resuming backup %s', $backupId));
            } elseif ($this->isRecentBackupPresent($params)) {
                return Status::OK;
            }

            $model  = $this->bootAkeebaBackup($profileId);
            $return = null;

            if ($backupId === null) {
                $return   = $this->startBackup($model, $profileId, (string) ($params->description ?? ''));
                $backupId = (string) ($return['backupid'] ?? '');
            }

            return $this->stepUntilDone($model, $profileId, $backupId, $timeLimit, $return);
        } catch (\Throwable $e) {
            $this->logTask('Akeeba Backup: ' . $e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }
    }

    /**
     * Starts a new backup and returns the Akeeba Engine return array.
     *
     * @param   object   $model        The Akeeba Backup model
     * @param   integer  $profileId    The backup profile to use
     * @param   string   $description  Backup description, empty for the Akeeba Backup default
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function startBackup($model, int $profileId, string $description): array
    {
        // Force populateState() to run before we override the state it sets.
        $model->getState();

        $model->setState('tag', self::BACKUP_TAG);
        $model->setState('profile', $profileId);
        $model->setState('description', trim($description));
        $model->setState('comment', '');

        $return = $model->startBackup();

        $this->logTask(
            \sprintf(
                'Akeeba Backup: started backup %s on profile #%d',
                $return['backupid'] ?? '(unknown)',
                $profileId
            )
        );

        return $return;
    }

    /**
     * Steps the backup until it finishes, fails or runs out of its time budget.
     *
     * @param   object      $model      The Akeeba Backup model
     * @param   integer     $profileId  The backup profile in use
     * @param   string      $backupId   The ID of the backup being run
     * @param   integer     $timeLimit  Seconds to spend here, zero to run to completion
     * @param   array|null  $return     Return array of the step already taken, if any
     *
     * @return  integer  The exit code
     *
     * @since   1.0.0
     */
    private function stepUntilDone($model, int $profileId, string $backupId, int $timeLimit, ?array $return): int
    {
        $deadline = $timeLimit > 0 ? microtime(true) + $timeLimit : null;

        while (true) {
            if ($return !== null) {
                if (!empty($return['Error'])) {
                    $this->logTask('Akeeba Backup: ' . $return['Error'], 'error');

                    return Status::KNOCKOUT;
                }

                if ((int) ($return['HasRun'] ?? 0) === 1) {
                    $this->logTask(
                        \sprintf(
                            'Akeeba Backup: finished backup %s (%s)',
                            $backupId,
                            $return['Archive'] ?? ''
                        )
                    );

                    return Status::OK;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    $this->logTask(
                        \sprintf(
                            'Akeeba Backup: backup %s reached %d%%, will resume on the next run',
                            $backupId,
                            (int) ($return['Progress'] ?? 0)
                        )
                    );

                    return Status::WILL_RESUME;
                }
            }

            $model->setState('tag', self::BACKUP_TAG);
            $model->setState('backupid', $backupId);
            $model->setState('profile', $profileId);

            $return = $model->stepBackup();
        }
    }

    /**
     * Is the site already covered by a backup young enough that we need not take another one?
     *
     * @param   object  $params  The task parameters
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function isRecentBackupPresent($params): bool
    {
        $maxAge = $this->getMaxBackupAge($params);

        if ($maxAge <= 0) {
            return false;
        }

        $age = $this->getLastCompleteBackupAge();

        if ($age === null || $age >= $maxAge * 3600) {
            return false;
        }

        $this->logTask(
            \sprintf(
                'Akeeba Backup: the last complete backup is %d hours old, the threshold is %d hours. Nothing to do.',
                (int) ($age / 3600),
                $maxAge
            )
        );

        return true;
    }

    /**
     * How old, in hours, the newest backup may be before we take a new one. Zero means "always".
     *
     * @param   object  $params  The task parameters
     *
     * @return  integer
     *
     * @since   1.0.0
     */
    private function getMaxBackupAge($params): int
    {
        switch ((string) ($params->freshness_source ?? 'quickicon')) {
            case 'always':
                return 0;

            case 'custom':
                return max(0, (int) ($params->max_age_hours ?? 24));

            default:
                // PluginHelper::getPlugin() only ever returns enabled plugins.
                $quickIcon = PluginHelper::getPlugin('quickicon', 'akeebabackup');

                if (empty($quickIcon)) {
                    return 0;
                }

                return max(0, (int) (new Registry($quickIcon->params))->get('maxbackupperiod', 24));
        }
    }

    /**
     * Age in seconds of the newest complete backup of any origin, or null when there is none.
     *
     * Unlike Akeeba's quick icon, which looks at the newest record whatever its state, a failed or
     * abandoned backup does not count here, so the task retries instead of assuming it is covered.
     *
     * @return  integer|null
     *
     * @since   1.0.0
     */
    private function getLastCompleteBackupAge(): ?int
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select('MAX(' . $db->quoteName('backupstart') . ')')
            ->from($db->quoteName('#__akeebabackup_backups'))
            ->where($db->quoteName('tag') . ' <> ' . $db->quote('restorepoint'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('complete'));

        $db->setQuery($query);

        $lastBackup = $db->loadResult();

        if (empty($lastBackup)) {
            return null;
        }

        $timestamp = strtotime($lastBackup . ' UTC');

        return $timestamp === false ? null : max(0, time() - $timestamp);
    }

    /**
     * Returns the ID of an unfinished backup of our own origin which is recent enough to resume.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function getResumableBackupId(): ?string
    {
        $tag   = self::BACKUP_TAG;
        $since = gmdate('Y-m-d H:i:s', time() - self::RESUME_WINDOW);
        $db    = $this->getDatabase();

        $query = $db->createQuery()
            ->select($db->quoteName('backupid'))
            ->from($db->quoteName('#__akeebabackup_backups'))
            ->where($db->quoteName('tag') . ' = :tag')
            ->where($db->quoteName('status') . ' = ' . $db->quote('run'))
            ->where($db->quoteName('backupstart') . ' >= :since')
            ->order($db->quoteName('id') . ' DESC')
            ->setLimit(1)
            ->bind(':tag', $tag)
            ->bind(':since', $since);

        $db->setQuery($query);

        return $db->loadResult() ?: null;
    }

    /**
     * Loads Akeeba Engine the way the component's own dispatcher does and returns the Backup model.
     *
     * @param   integer  $profileId  The backup profile to activate
     *
     * @return  object  The Akeeba Backup model
     *
     * @since   1.0.0
     */
    private function bootAkeebaBackup(int $profileId)
    {
        $app = $this->getApplication();

        require_once self::COMPONENT_PATH . '/vendor/autoload.php';
        @include_once self::COMPONENT_PATH . '/version.php';

        if (!\defined('AKEEBABACKUP_VERSION')) {
            \define('AKEEBABACKUP_VERSION', 'dev');
            \define('AKEEBABACKUP_DATE', date('Y-m-d'));
        }

        if (!\defined('AKEEBABACKUP_PRO')) {
            \define('AKEEBABACKUP_PRO', is_dir(self::COMPONENT_PATH . '/AliceChecks'));
        }

        if (!\defined('AKEEBAENGINE')) {
            \define('AKEEBAENGINE', 1);
        }

        if (!\defined('AKEEBAROOT')) {
            \define('AKEEBAROOT', realpath(self::COMPONENT_PATH . '/vendor/akeeba/engine/engine'));
        }

        if (!\defined('AKEEBA_CACERT_PEM')) {
            \define(
                'AKEEBA_CACERT_PEM',
                class_exists(CaBundle::class)
                    ? CaBundle::getBundledCaBundlePath()
                    : JPATH_LIBRARIES . '/src/Http/Transport/cacert.pem'
            );
        }

        if (!\defined('AKEEBA_BACKUP_ORIGIN')) {
            \define('AKEEBA_BACKUP_ORIGIN', self::BACKUP_TAG);
        }

        $app->getLanguage()->load('com_akeebabackup', JPATH_ADMINISTRATOR);

        /** @var MVCFactoryServiceInterface $component */
        $component  = $app->bootComponent('com_akeebabackup');
        $mvcFactory = $component->getMVCFactory();

        EnginePlatform::addPlatform('joomla', self::COMPONENT_PATH . '/platform/Joomla');
        EngineFactory::getSecureSettings()->setKeyFilename(self::COMPONENT_PATH . '/serverkey.php');
        EngineFactory::setPushClass(PushMessages::class);

        PushMessages::$mvcFactory = $mvcFactory;

        // Referencing the platform instance is what triggers Akeeba Engine's autoloader.
        EnginePlatform::getInstance();

        JoomlaEnginePlatform::setDbDriver($this->getDatabase());

        /**
         * The active profile is read from this static property first, so setting it here — rather
         * than defining AKEEBA_PROFILE — keeps a second backup task in the same run from inheriting
         * the profile of the first one.
         */
        JoomlaEnginePlatform::$profile_id = $profileId;

        if (method_exists($app, 'getSession')) {
            $app->getSession()->set('akeebabackup.profile', $profileId);
        }

        EnginePlatform::getInstance()->load_configuration($profileId);

        return $mvcFactory->createModel('Backup', 'Administrator', ['ignore_request' => true]);
    }
}
