<?php

declare(strict_types=1);

namespace Charter;

/**
 * The person the boat is being booked for.
 *
 * This type exists because of the strangest thing in the original file. The
 * booking route built a customer, carefully, with a thought-out fallback for
 * every field a form might leave empty — "Promo", "Code", a zip of 00000, a
 * service email address, a phone of ten zeroes. Fourteen lines of defence.
 *
 * And then the request body built a second customer, inline, thirty lines
 * further down, out of the raw variables, with no fallbacks at all. The first
 * one was never read again. Not once.
 *
 * So the defence was inert, and the operator received bookings whose customer
 * was a row of empty strings. The form in the browser insisted on those fields,
 * which is why nobody noticed — but that insisting lives in the browser, and
 * the endpoint was open to anybody who did not use it.
 *
 * The repair is not to restore the fallbacks. A booking with no name is not a
 * booking, and inventing a name for it is how the empty ones got in. The
 * repair is that there is one customer, it is checked, and a booking without
 * one does not happen.
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

        // The original defaulted this to 1 and never looked it up, while
        // holding the country list in the same file and serving it to the
        // dropdown from a route three functions away. Nobody sees a wrong
        // country until somebody needs the nationality for a crew list.
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
