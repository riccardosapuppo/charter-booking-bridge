<?php

declare(strict_types=1);

namespace Charter;

/**
 * Notes held in memory, for the checks and the measurement.
 */
final class KeptNotes implements Notes
{
    /** @var list<array{reservation:string,email:string,message:string}> */
    private array $kept = [];

    public function keep(string $reservation, Customer $who, string $message): void
    {
        $this->kept[] = ['reservation' => $reservation, 'email' => $who->email, 'message' => $message];
    }

    /** @return list<array{reservation:string,email:string,message:string}> */
    public function all(): array
    {
        return $this->kept;
    }
}
