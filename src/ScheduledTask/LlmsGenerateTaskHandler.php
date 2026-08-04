<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAiDiscovery\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAiDiscovery\Service\Llms\LlmsDocumentGenerator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Führt die geplante Generierung aus. Ein Fehler wird geloggt und die Aufgabe neu eingeplant,
 * damit ein einzelner Ausfall (etwa eine kurzzeitig unerreichbare Datenbank) nicht dazu führt,
 * dass die Dateien dauerhaft veralten.
 */
#[AsMessageHandler(handles: LlmsGenerateTask::class)]
final class LlmsGenerateTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly LlmsDocumentGenerator $documentGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $written = $this->documentGenerator->generateAll(Context::createDefaultContext());

        $this->logger->info('llms-Dateien neu erzeugt', ['documents' => $written]);
    }
}
