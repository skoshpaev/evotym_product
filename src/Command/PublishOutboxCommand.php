<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\OutboxMessageRepository;
use App\Service\Api\RabbitMQServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:publish-outbox', description: 'Publish pending outbox events.')]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly RabbitMQServiceInterface $rabbitMQService,
        private readonly OutboxMessageRepository $outboxMessageRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $createdProducts = $this->outboxMessageRepository->findCreatedOrdered();
        $published = $this->rabbitMQService->publishPendingOutbox($createdProducts);

        $io->success(sprintf('Published %d outbox event(s).', $published));

        return Command::SUCCESS;
    }
}
