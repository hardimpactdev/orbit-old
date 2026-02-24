<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Facades\DB;

final class TldService
{
    private const array RESERVED_TLDS = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 'io', 'co', 'uk', 'us', 'dev'];

    /**
     * Update a node's TLD and all associated deployment domains.
     *
     * Pure database logic — no SSH, no infrastructure side effects.
     *
     * @return array{old_tld: string, new_tld: string, deployments_updated: int}
     *
     * @throws \InvalidArgumentException
     */
    public function updateNodeTld(Node $node, string $newTld): array
    {
        $newTld = strtolower(ltrim($newTld, '.'));

        $validationError = $this->validateTld($newTld);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        $oldTld = $node->tld;

        if ($oldTld === $newTld) {
            return [
                'old_tld' => $oldTld ?? '',
                'new_tld' => $newTld,
                'deployments_updated' => 0,
            ];
        }

        return DB::transaction(function () use ($node, $oldTld, $newTld): array {
            $updates = ['tld' => $newTld];

            if ($node->custom_tld !== null) {
                $updates['custom_tld'] = $newTld;
            }

            $node->update($updates);

            $deploymentsUpdated = 0;

            if ($oldTld !== null) {
                $suffix = ".{$oldTld}";
                $newSuffix = ".{$newTld}";

                $deployments = Deployment::where('node_id', $node->id)
                    ->where('status', DeploymentStatus::Active)
                    ->where('domain', 'like', "%{$suffix}")
                    ->get();

                foreach ($deployments as $deployment) {
                    $newDomain = substr($deployment->domain, 0, -strlen($suffix)) . $newSuffix;
                    $newUrl = $deployment->url !== null
                        ? preg_replace('/' . preg_quote($suffix, '/') . '$/', $newSuffix, $deployment->url)
                        : null;

                    $deployment->update([
                        'domain' => $newDomain,
                        'url' => $newUrl,
                    ]);
                    $deploymentsUpdated++;
                }
            }

            return [
                'old_tld' => $oldTld ?? '',
                'new_tld' => $newTld,
                'deployments_updated' => $deploymentsUpdated,
            ];
        });
    }

    public function validateTld(string $tld): ?string
    {
        $tld = strtolower(ltrim($tld, '.'));

        if (empty($tld)) {
            return 'TLD cannot be empty';
        }

        if (! preg_match('/^[a-z0-9-]+$/', $tld)) {
            return 'TLD must contain only lowercase letters, numbers, and hyphens';
        }

        if (strlen($tld) < 2 || strlen($tld) > 63) {
            return 'TLD must be between 2 and 63 characters';
        }

        if (in_array($tld, self::RESERVED_TLDS, true)) {
            return "Cannot use reserved TLD: {$tld}";
        }

        return null;
    }
}
