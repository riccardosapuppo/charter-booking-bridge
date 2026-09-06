<?php

declare(strict_types=1);

namespace Charter;

/**
 * The person the boat is being booked for.
 *
 * One customer, built once, checked once, and sent. The body that goes to the
 * operator is built from this object and from nothing else, which is what makes
 * "the anagraphic that arrives from the form is the anagraphic that arrives at
 * the operator" a sentence about the code rather than about an intention.
 *
 * Checked means checked: a name and a surname, an address that is an address,
 * and a country the operator actually has on its list. A booking with no name
 * is not a booking, and there are no fallbacks here — inventing a name for an
 * empty form is how empty ones get in.
 *
 * The browser insists on those fields too. That insisting lives in the browser,
 * and this endpoint is open to everything that is not a browser.
 */
final class Customer
{
    private function __construct(
        public readonly string $name,
        public readonly string $surname,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $address,
        public readonly string $zip,
        public readonly string $city,
        public readonly int $countryId,
        public readonly string $message,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $countries what the manager says exists
     *
     * @throws NotACustomer with a message written to be shown
     */
    public static function from(array $input, array $countries): self
    {
        $said = static fn (string $field): string => trim((string) ($input[$field] ?? ''));

        $name = $said('name');
        $surname = $said('surname');
        $email = $said('email');

        if ($name === '' || $surname === '') {
            throw new NotACustomer('A booking needs a name and a surname.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new NotACustomer('That email address cannot be right.');
        }

        $countryId = (int) ($input['countryId'] ?? 0);

        // Looked up, not defaulted. The same list is behind the dropdown the
        // visitor chose from, and nobody sees a wrong country until somebody
        // needs the nationality for a crew list.
        if (!isset($countries[(string) $countryId])) {
            throw new NotACustomer('Please choose a country from the list.');
        }

        return new self(
            $name,
            $surname,
            $email,
            $said('phone'),
            $said('address'),
            $said('zip'),
            $said('city'),
            $countryId,
            $said('message'),
        );
    }

    /**
     * The shape the manager wants. One place, used by the one body that is
     * sent — which is the entire point of the class.
     *
     * @return array<string,mixed>
     */
    public function forManager(): array
    {
        return [
            'name' => $this->name,
            'surname' => $this->surname,
            'company' => false,
            'vatNr' => '',
            'address' => $this->address,
            'zip' => $this->zip,
            'city' => $this->city,
            'countryId' => (string) $this->countryId,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => '',
            'skype' => '',
        ];
    }

    /**
     * The stand-in used when the bridge asks the manager for a price rather
     * than for a booking. It is not a person and is not meant to look like one.
     *
     * @return array<string,mixed>
     */
    public static function nobody(int $countryId): array
    {
        return [
            'name' => 'Quote',
            'surname' => 'Only',
            'company' => false,
            'vatNr' => '',
            'address' => '-',
            'zip' => '-',
            'city' => '-',
            'countryId' => (string) $countryId,
            'email' => 'quote@example.invalid',
            'phone' => '-',
            'mobile' => '',
            'skype' => '',
        ];
    }
}
