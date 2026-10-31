<?php
namespace Src\Service;

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Storage\JsonStorage;
use Src\Model\Trade;

class TradeService
{
    private JsonStorage $storage;
    private ScheduleService $scheduleService;

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getTradesFilePath());
        $this->scheduleService = new ScheduleService();
    }

    /**
     * Gets all trades.
     * @return Trade[]
     */
    public function getAllTrades(): array
    {
        $data = $this->storage->read();
        $tradesData = $data['trades'] ?? [];
        
        $trades = [];
        foreach ($tradesData as $tData) {
            $trades[] = Trade::fromArray($tData);
        }
        return $trades;
    }

    /**
     * Saves trades array to trades.json.
     * @param Trade[] $trades
     */
    public function saveTrades(array $trades): bool
    {
        $tradesData = [];
        foreach ($trades as $trade) {
            $tradesData[] = $trade->toArray();
        }
        return $this->storage->write(['trades' => $tradesData]);
    }

    /**
     * Requests a trade for a given block.
     */
    public function requestTrade(string $blockId, string $userId): bool
    {
        return JsonStorage::synchronized(function () use ($blockId, $userId) {
        $auth = new AuthManager();
        if (!$auth->isDutyEligibleUserId($userId)) {
            return false;
        }

        $block = $this->scheduleService->getBlockById($blockId);
        if (!$block || $block->getAssignedUserId() !== $userId || $block->getStatus() !== 'assigned') {
            return false;
        }

        // Only current/future blocks may be offered (no trading away past shifts).
        if ($block->getEndTimestamp() <= time()) {
            return false;
        }

        $trades = $this->getAllTrades();
        foreach ($trades as $existing) {
            if ($existing->getBlockId() === $blockId && $existing->getStatus() === 'open') {
                return false;
            }
        }

        // Create the trade
        $tradeId = 'trd_' . bin2hex(random_bytes(16));
        $newTrade = new Trade(
            $tradeId,
            $blockId,
            $userId,
            null,
            'open',
            date('Y-m-d H:i:s')
        );
        $trades[] = $newTrade;

        // Save blocks first (source of truth), then trades
        $blocks = $this->scheduleService->getAllBlocks();
        foreach ($blocks as $b) {
            if ($b->getId() === $blockId) {
                $b->setStatus('trade_open');
                break;
            }
        }

        if ($this->scheduleService->saveBlocks($blocks)) {
            if ($this->saveTrades($trades)) {
                return true;
            }
            // Rollback: revert block status on trade save failure
            $blocks = $this->scheduleService->getAllBlocks();
            foreach ($blocks as $b) {
                if ($b->getId() === $blockId) {
                    $b->setStatus('assigned');
                    break;
                }
            }
            $this->scheduleService->saveBlocks($blocks);
        }

        return false;
        });
    }

    /**
     * Cancels an open trade (only before someone accepts).
     * Sets the distinct 'cancelled' status (user withdrawal), unlike
     * 'rejected' which is reserved for admin decisions.
     */
    public function cancelTrade(string $tradeId, string $userId): bool
    {
        return JsonStorage::synchronized(function () use ($tradeId, $userId) {
        $trades = $this->getAllTrades();
        foreach ($trades as $trade) {
            if ($trade->getId() === $tradeId && $trade->getRequesterId() === $userId && $trade->getStatus() === 'open') {
                $trade->setStatus('cancelled');
                $trade->setResolvedAt(date('Y-m-d H:i:s'));

                // Save blocks first, then trades
                $blocks = $this->scheduleService->getAllBlocks();
                foreach ($blocks as $b) {
                    if ($b->getId() === $trade->getBlockId()) {
                        $b->setStatus('assigned');
                        break;
                    }
                }

                if ($this->scheduleService->saveBlocks($blocks)) {
                    if ($this->saveTrades($trades)) {
                        return true;
                    }
                    // Rollback block status
                    $blocks = $this->scheduleService->getAllBlocks();
                    foreach ($blocks as $b) {
                        if ($b->getId() === $trade->getBlockId()) {
                            $b->setStatus('trade_open');
                            break;
                        }
                    }
                    $this->scheduleService->saveBlocks($blocks);
                }
            }
        }
        return false;
        });
    }

    /**
     * Accepts an open trade from the marketplace.
     */
    public function acceptTrade(string $tradeId, string $acceptingUserId): bool
    {
        return JsonStorage::synchronized(function () use ($tradeId, $acceptingUserId) {
        $auth = new AuthManager();
        if (!$auth->isDutyEligibleUserId($acceptingUserId)) {
            return false;
        }

        $trades = $this->getAllTrades();
        foreach ($trades as $trade) {
            if ($trade->getId() === $tradeId && $trade->getStatus() === 'open') {
                // The offer was only open for future blocks (requestTrade
                // enforces this). Without re-checking, a shift could be
                // accepted long after it ended, and the admin approval would
                // then rewrite a historical duty assignment — which changes
                // who the billing attributes those hours to.
                $block = $this->scheduleService->getBlockById($trade->getBlockId());
                if (!$block || $block->getEndTimestamp() <= time()) {
                    return false;
                }

                foreach ($trades as $other) {
                    if ($other->getId() !== $tradeId
                        && $other->getBlockId() === $trade->getBlockId()
                        && $other->getStatus() === 'pending_approval') {
                        return false;
                    }
                }
                // You cannot accept your own trade request
                if ($trade->getRequesterId() === $acceptingUserId) {
                    return false;
                }

                $trade->setStatus('pending_approval');
                $trade->setReceiverId($acceptingUserId);
                $trade->setAcceptedAt(date('Y-m-d H:i:s'));

                // Save blocks first, then trades
                $blocks = $this->scheduleService->getAllBlocks();
                foreach ($blocks as $b) {
                    if ($b->getId() === $trade->getBlockId()) {
                        $b->setStatus('trade_pending');
                        break;
                    }
                }

                if ($this->scheduleService->saveBlocks($blocks)) {
                    if ($this->saveTrades($trades)) {
                        return true;
                    }
                    // Rollback
                    $blocks = $this->scheduleService->getAllBlocks();
                    foreach ($blocks as $b) {
                        if ($b->getId() === $trade->getBlockId()) {
                            $b->setStatus('trade_open');
                            break;
                        }
                    }
                    $this->scheduleService->saveBlocks($blocks);
                }
            }
        }
        return false;
        });
    }

/**
 * Admin approves the trade, completing the swap.
 *
 * The caller must be a logged-in administrator. The check lives here (not
 * only in the controller) so that approveTrade()/rejectTrade() cannot be
 * reused from a new route without inheriting full privileges.
 */
    public function approveTrade(string $tradeId): bool
    {
        return JsonStorage::synchronized(function () use ($tradeId) {
        $auth = new AuthManager();
        if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
            return false;
        }
        $trades = $this->getAllTrades();
        foreach ($trades as $trade) {
            if ($trade->getId() === $tradeId && $trade->getStatus() === 'pending_approval') {
                $receiverId = $trade->getReceiverId();
                if ($receiverId === null || !$auth->isDutyEligibleUserId($receiverId)) {
                    return false;
                }

                // The block must still be in the future. A shift that has
                // already ended must not be reassigned: the approval would
                // move historical hours to another employee in the billing and
                // in the printed duty-plan evidence.
                $block = $this->scheduleService->getBlockById($trade->getBlockId());
                if (!$block || $block->getEndTimestamp() <= time()) {
                    return false;
                }

                $trade->setStatus('approved');
                $trade->setResolvedAt(date('Y-m-d H:i:s'));

                // Save blocks first, then trades
                $blocks = $this->scheduleService->getAllBlocks();
                foreach ($blocks as $b) {
                    if ($b->getId() === $trade->getBlockId()) {
                        $b->setAssignedUserId($trade->getReceiverId());
                        $b->setStatus('assigned');
                        break;
                    }
                }

                if ($this->scheduleService->saveBlocks($blocks)) {
                    if ($this->saveTrades($trades)) {
                        return true;
                    }
                    // Rollback
                    $blocks = $this->scheduleService->getAllBlocks();
                    foreach ($blocks as $b) {
                        if ($b->getId() === $trade->getBlockId()) {
                            $b->setAssignedUserId($trade->getRequesterId());
                            $b->setStatus('trade_pending');
                            break;
                        }
                    }
                    $this->scheduleService->saveBlocks($blocks);
                }
            }
        }
        return false;
        });
    }

/**
 * Admin rejects the trade, putting it back to its original assigned user.
 * Same authorization requirement as approveTrade().
 */
    public function rejectTrade(string $tradeId): bool
    {
        return JsonStorage::synchronized(function () use ($tradeId) {
        $auth = new AuthManager();
        if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
            return false;
        }
        $trades = $this->getAllTrades();
        foreach ($trades as $trade) {
            if ($trade->getId() === $tradeId && $trade->getStatus() === 'pending_approval') {
                $trade->setStatus('rejected');
                $trade->setResolvedAt(date('Y-m-d H:i:s'));

                // Save blocks first, then trades
                $blocks = $this->scheduleService->getAllBlocks();
                foreach ($blocks as $b) {
                    if ($b->getId() === $trade->getBlockId()) {
                        $b->setStatus('assigned');
                        break;
                    }
                }

                if ($this->scheduleService->saveBlocks($blocks)) {
                    if ($this->saveTrades($trades)) {
                        return true;
                    }
                    // Rollback
                    $blocks = $this->scheduleService->getAllBlocks();
                    foreach ($blocks as $b) {
                        if ($b->getId() === $trade->getBlockId()) {
                            $b->setStatus('trade_pending');
                            break;
                        }
                    }
                    $this->scheduleService->saveBlocks($blocks);
                }
            }
        }
        return false;
        });
    }

/**
 * Closes open/pending trades for a block (e.g. after manual reassignment).
 * Runs under the cross-request lock: callers hold it too (assignUserToBlock),
 * and synchronized() is re-entrant, so this is safe either way.
 */
    public function cancelTradesForBlock(string $blockId): bool
    {
        return JsonStorage::synchronized(function () use ($blockId) {
        $trades = $this->getAllTrades();
        $changed = false;
        $now = date('Y-m-d H:i:s');

        foreach ($trades as $trade) {
            if ($trade->getBlockId() !== $blockId) {
                continue;
            }
            if (in_array($trade->getStatus(), ['open', 'pending_approval'], true)) {
                // User-Withdrawal / Admin-Reassign: Angebot wird zurückgezogen.
                // Status 'cancelled' (user withdrawal) – 'rejected' ist für
                // admin-getroffene Ablehnungen reserviert.
                $trade->setStatus('cancelled');
                $trade->setResolvedAt($now);
                $changed = true;
            }
        }

        return $changed ? $this->saveTrades($trades) : true;
        });
    }

    /**
     * Cancels (rejects) all open/pending trades referencing a deleted user (as requester or receiver).
     * Blocks left in a trade state without any remaining active trade are reset to 'assigned'.
     */
    public function removeUserTrades(string $userId): bool
    {
        return JsonStorage::synchronized(function () use ($userId) {
        $trades = $this->getAllTrades();
        $changed = false;
        $now = date('Y-m-d H:i:s');
        $affectedBlocks = [];

        foreach ($trades as $trade) {
            if (in_array($trade->getStatus(), ['open', 'pending_approval'], true)
                && ($trade->getRequesterId() === $userId || $trade->getReceiverId() === $userId)
            ) {
                $trade->setStatus('rejected');
                $trade->setResolvedAt($now);
                $affectedBlocks[$trade->getBlockId()] = true;
                $changed = true;
            }
        }

        if (!$changed) {
            return true;
        }
        if (!$this->saveTrades($trades)) {
            return false;
        }

        // Repair blocks stuck in a trade state with no active trade left.
        $blocks = $this->scheduleService->getAllBlocks();
        $blocksChanged = false;
        foreach ($blocks as $block) {
            if (!isset($affectedBlocks[$block->getId()])) {
                continue;
            }
            if (!in_array($block->getStatus(), ['trade_open', 'trade_pending'], true)) {
                continue;
            }
            $stillActive = false;
            foreach ($trades as $trade) {
                if ($trade->getBlockId() === $block->getId()
                    && in_array($trade->getStatus(), ['open', 'pending_approval'], true)
                ) {
                    $stillActive = true;
                    break;
                }
            }
            if (!$stillActive) {
                $block->setStatus('assigned');
                $blocksChanged = true;
            }
        }

        return $blocksChanged ? $this->scheduleService->saveBlocks($blocks) : true;
        });
    }

    /**
     * Removes all trade records (e.g. when resetting the schedule).
     */
    public function clearAllTrades(): bool
    {
        return $this->storage->write(['trades' => []]);
    }
}
