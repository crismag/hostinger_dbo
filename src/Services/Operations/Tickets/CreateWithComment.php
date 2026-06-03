<?php

declare(strict_types=1);

namespace App\Services\Operations\Tickets;

use App\Core\ApiException;
use App\Services\Operations\ServiceContext;
use App\Services\Operations\ServiceOperation;
use PDOException;
use Throwable;

/**
 * Transactional "create ticket + first comment": inserts a ticket and its first
 * comment atomically. If either write fails, the whole operation rolls back —
 * the kind of multi-write transaction the generic gateway intentionally does not
 * expose. All values are bound; identifiers are literal, trusted code.
 */
final class CreateWithComment implements ServiceOperation
{
    public function inputSpec(): array
    {
        return [
            'subject' => ['type' => 'string', 'required' => true],
            'body' => ['type' => 'string', 'required' => false],
            'priority' => ['type' => 'string', 'required' => false],
            'customer_id' => ['type' => 'int', 'required' => false],
            'agent_id' => ['type' => 'int', 'required' => false],
            'comment' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $input, ServiceContext $context): array
    {
        $pdo = $context->pdo();
        $priority = in_array($input['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true)
            ? $input['priority']
            : 'normal';

        $pdo->beginTransaction();
        try {
            $ticket = $pdo->prepare(
                "INSERT INTO `tickets` (`customer_id`, `agent_id`, `subject`, `body`, `status`, `priority`)"
                . " VALUES (:customer_id, :agent_id, :subject, :body, 'open', :priority)"
            );
            $ticket->execute([
                ':customer_id' => $input['customer_id'] ?? null,
                ':agent_id' => $input['agent_id'] ?? null,
                ':subject' => $input['subject'],
                ':body' => $input['body'] ?? null,
                ':priority' => $priority,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            $comment = $pdo->prepare(
                "INSERT INTO `comments` (`ticket_id`, `author`, `body`) VALUES (:ticket_id, 'system', :body)"
            );
            $comment->execute([':ticket_id' => $ticketId, ':body' => $input['comment']]);

            $pdo->commit();

            return ['ticket_id' => $ticketId, 'comment_added' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Surface a safe, unified failure; the rollback left nothing behind.
            throw $e instanceof PDOException
                ? new ApiException('OBJECT_CONFLICT', 'Could not create ticket with comment', 409)
                : $e;
        }
    }
}
