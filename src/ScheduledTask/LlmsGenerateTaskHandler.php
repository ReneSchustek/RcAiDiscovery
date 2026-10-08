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
 * Führt die geplante Generierung aus und verzichtet auf ein eigenes `catch`.
 *
 * Fehler einer einzelnen Domain fängt `LlmsDocumentGenerator::generateAll()` schon ab. Was bis hier
 * durchschlägt, etwa eine unerreichbare Datenbank, protokolliert der Ausführer des Kerns und plant
 * die Aufgabe neu ein, weil `LlmsGenerateTask::shouldRescheduleOnFailure()` das verlangt.
 */
#[AsMessageHandler(handles: LlmsGenerateTask::class)]
final class LlmsGenerateTaskHandler extends ScheduledTaskHandler
{
    /**
     * `$exceptionLogger` gehört der Basisklasse, `$logger` meldet den erfolgreichen Lauf; beide
     * bekommen in der `services.xml` denselben Dienst `logger`.
     *
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
        // Ohne `force`: in der Verwaltung bearbeitete Dokumente bleiben unangetastet.
        $written = $this->documentGenerator->generateAll(Context::createDefaultContext());

        $this->logger->info('llms-Dateien neu erzeugt', ['documents' => $written]);
    }
}
