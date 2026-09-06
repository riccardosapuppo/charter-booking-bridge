<?php

declare(strict_types=1);

namespace Charter;

/**
 * The operator's booking system, as this bridge speaks to it.
 *
 * Two things live here rather than at every call site. The account, in the two
 * shapes the two families of endpoints want — flat at the top of the body for
 * the catalogue, nested under `credentials` for availability and booking —
 * taken from {@see Secrets}, which reads the environment.
 *
 * And what to do when the answer is not an answer. Reaching nobody is worth
 * trying again, so it is tried again: a boat's page makes ten calls, and one
 * dropped packet anywhere in that chain must not be a broken page for the
 * visitor. Being told no is not worth trying again, so it is not, and the
 * operator's own error text goes into {@see Answer}'s second string, the one
 * that is logged rather than shown.
 */
final class Manager
{
    public function __construct(
        private readonly Transport $transport,
        private readonly int $attempts = 3,
    ) {
    }

    /**
     * A catalogue list. The account goes at the top level of the body, which is
     * what this family of endpoints expects.
     *
     * @return array<string,mixed>
     */
    public function catalogue(string $path): array
    {
        return $this->ask('POST', $path, Secrets::flat());
    }

    /**
     * Availability, quotes and bookings: the account nested under
     * `credentials`, alongside the rest of the body.
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    public function booking(string $path, array $body): array
    {
        return $this->ask('POST', $path, Secrets::nested($body));
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function ask(string $method, string $path, array $body): array
    {
        $last = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                $said = $this->transport->send($method, $path, $body);
            } catch (Unreachable $missed) {
                // Reaching nobody is worth trying again. Being told no is not.
                $last = $missed;

                continue;
            }

            $status = $said['status'] ?? 'OK';

            if ($status !== 'OK') {
                throw new ManagerSaidNo(
                    $path . ' answered ' . (string) $status
                        . ' ' . (string) ($said['errorCode'] ?? '')
                        . ' ' . (string) ($said['errorMessage'] ?? ''),
                );
            }

            return $said;
        }

        throw new Unreachable(
            $path . ' could not be reached in ' . $this->attempts . ' attempts: '
                . ($last?->getMessage() ?? 'no reason given'),
        );
    }
}
