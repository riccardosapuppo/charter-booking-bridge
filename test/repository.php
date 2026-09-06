<?php

declare(strict_types=1);

/**
 * Checks about the repository itself rather than about the bridge: that the
 * README says what the program says, that every command it gives exists, and
 * that nothing here names anybody.
 *
 * A README is prose, and prose is the part that goes stale without a sound.
 * These are what stop it.
 */
return [
    'nothing here begins with a byte order mark' => static function (): void {
        // Three bytes, before the opening tag, in a file WordPress includes on
        // every single request. They are output: they go out before any header
        // can, which is where "headers already sent" comes from, and they sit
        // in front of the body of every REST answer, where they stop the JSON
        // from parsing. The page's `await res.json()` throws, the visitor is
        // told the booking failed, and whether it happens at all depends on how
        // output buffering is configured — so it happens in production and not
        // on the laptop.
        //
        // A mark comes back through an editor rather than through a commit,
        // which is why this is a check and not a memory.
        $bad = [];
        $read = 0;

        foreach (check_tracked_files() as $file) {
            $handle = @fopen(check_root() . '/' . $file, 'rb');

            if ($handle === false) {
                continue;
            }

            $first = (string) fread($handle, 3);
            fclose($handle);
            $read++;

            if ($first === "\xEF\xBB\xBF") {
                $bad[] = $file;
            }
        }

        // A check that examined nothing passes, so how many were examined is
        // part of what is asserted.
        check_true($read >= 25, 'only ' . $read . ' files were read, so this check looked almost nowhere');

        check_same([], $bad, 'these files start with a byte order mark: ' . implode(', ', $bad));

        // And a demonstration that the check is about something: those three
        // bytes in front of an answer make it unreadable.
        check_same(
            null,
            json_decode("\xEF\xBB\xBF" . '{"status":"OK"}', true),
            'a byte order mark in front of a JSON body no longer breaks it, so this check is testing nothing',
        );
    },

    'nothing here looks like an account, a person, or somebody\'s host' => static function (): void {
        $found = [];
        $read = 0;

        foreach (check_tracked_files() as $file) {
            if (!preg_match('/\.(php|md|yml|yaml|json|txt|example)$/', $file) && basename($file) !== 'LICENSE') {
                continue;
            }

            $text = (string) file_get_contents(check_root() . '/' . $file);
            $read++;

            foreach (explode("\n", $text) as $number => $line) {
                // An email address that is not one of the reserved domains the
                // standards keep for exactly this purpose.
                if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $said) === 1) {
                    if (!preg_match('/(example\.(invalid|com|org|net)|@invented|noreply@)/', $said[0])) {
                        $found[] = $file . ':' . ($number + 1) . ' ' . $said[0];
                    }
                }

                // A hostname that is not an example one. The supplier's host is
                // the operator's business, not this repository's.
                if (preg_match('#https?://([a-z0-9.-]+)#i', $line, $said) === 1) {
                    // Reserved for documentation, and nowhere else: the .example
                    // and .invalid top levels, example.com and its siblings,
                    // localhost, and the two sites a licence and a workflow have
                    // to name.
                    $allowed = '/(\.(example|invalid|test|localhost)$|^example\.(com|org|net)$'
                        . '|^localhost$|^127\.0\.0\.1$|^opensource\.org$|^github\.com$)/i';

                    if (preg_match($allowed, $said[1]) !== 1) {
                        $found[] = $file . ':' . ($number + 1) . ' ' . $said[0];
                    }
                }
            }
        }

        check_true($read >= 20, 'only ' . $read . ' files were searched, so this check looked almost nowhere');

        check_same([], $found, 'these lines name something real: ' . implode(' | ', $found));
    },

    'every command the README gives is one that is here' => static function (): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');

        preg_match_all('/^php (bin\/[a-z]+\.php)/m', $readme, $said);

        $named = array_unique($said[1]);

        check_true(
            count($named) >= 3,
            'only ' . count($named) . ' commands were found in the README, so this check is looking in the wrong place',
        );

        $missing = array_values(array_filter(
            $named,
            static fn (string $one): bool => !is_file(check_root() . '/' . $one),
        ));

        check_same([], $missing, 'the README gives commands that are not here: ' . implode(', ', $missing));

        // And the other way round, which is the half that goes stale: a script
        // nobody is told about is a script nobody runs.
        $unmentioned = [];

        foreach (glob(check_root() . '/bin/*.php') ?: [] as $file) {
            if (!in_array('bin/' . basename($file), $named, true)) {
                $unmentioned[] = basename($file);
            }
        }

        check_same([], $unmentioned, 'these are in bin/ and the README never mentions them: ' . implode(', ', $unmentioned));
    },

    'every guarantee the README opens with is one the measurement prints' => static function (): void {
        // The table at the top of the README is the list of things this
        // repository says about itself. Each row is a sentence, and each of
        // those sentences is the title of a claim that bin/measure.php
        // recomputes. A sentence that stops matching a claim is a sentence
        // nothing is checking any more.
        $readme = (string) file_get_contents(check_root() . '/README.md');

        preg_match_all('/^\| \*\*(.+?)\*\* \|/m', $readme, $said);

        $promised = $said[1];

        check_true(
            count($promised) >= 8,
            'only ' . count($promised) . ' guarantees were found in the README table, so this check is looking in the wrong place',
        );

        $titles = array_map(
            static fn (Charter\Claim $claim): string => $claim->title,
            Charter\Claims::all(),
        );

        check_same(
            count($titles),
            count($promised),
            'the README lists ' . count($promised) . ' guarantees and the measurement computes ' . count($titles),
        );

        $unmeasured = [];

        foreach ($promised as $number => $sentence) {
            // The README ends each one with a full stop; the claim titles do
            // not carry one.
            $sentence = rtrim($sentence, '.');

            if (($titles[$number] ?? '') !== $sentence) {
                $unmeasured[] = $sentence . ' (the measurement says: ' . ($titles[$number] ?? 'nothing') . ')';
            }
        }

        check_same([], $unmeasured, 'these are promised in the README and not measured: ' . implode(' | ', $unmeasured));
    },

    'the figures in the README are the ones the measurement prints' => static function (): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');

        check_true($readme !== '', 'the README could not be read, so nothing was compared');

        // The guarantees table at the top of the README, which is prose and
        // therefore the part that goes stale without a sound.
        $table = [];

        if (preg_match('/^\| \*\*.+\n(?:\|.*\n)+/m', $readme, $said) === 1) {
            $table = explode("\n", $said[0]);
        }

        check_true($table !== [], 'the README no longer has a guarantees table, so nothing was compared');

        $printed = check_measurement();

        $figures = [];

        foreach ($table as $row) {
            preg_match_all('/\*\*([\d.,]+)\*\*/', $row, $said);

            foreach ($said[1] as $figure) {
                $figures[] = $figure;
            }
        }

        check_true(
            count($figures) >= 8,
            'only ' . count($figures) . ' figures were found in the README guarantees table, so this check is looking in the wrong place',
        );

        $missing = [];

        foreach (array_unique($figures) as $figure) {
            if (!str_contains($printed, $figure)) {
                $missing[] = $figure;
            }
        }

        check_same([], $missing, 'the README quotes figures the measurement does not print: ' . implode(', ', $missing));
    },

    'the README prints what the measurement prints' => static function (): void {
        // The whole measurement appears in the README, in full, in a code
        // block. A block of output pasted into prose is a block that was true
        // once, so it is regenerated and compared here as well as in CI: the
        // program is the copy that counts.
        $readme = (string) file_get_contents(check_root() . '/README.md');

        $after = strstr($readme, '## The measurement, in full');

        check_true(is_string($after), 'the README no longer carries the measurement, so nothing was compared');

        check_same(
            1,
            preg_match('/```\n(.*?)\n```/s', (string) $after, $said),
            'the measurement section of the README has no code block in it',
        );

        $quoted = trim(str_replace("\r", '', $said[1]), "\n");
        $printed = trim(str_replace("\r", '', check_measurement()), "\n");

        check_true(strlen($quoted) > 2000, 'the block quoted in the README is ' . strlen($quoted) . ' characters, which is not the measurement');

        if ($quoted !== $printed) {
            $quotedLines = explode("\n", $quoted);
            $printedLines = explode("\n", $printed);
            $first = 'the two are of different lengths';

            foreach ($printedLines as $number => $line) {
                if (($quotedLines[$number] ?? null) !== $line) {
                    $first = 'line ' . ($number + 1) . ': the README says '
                        . check_show($quotedLines[$number] ?? null) . ' and the program says ' . check_show($line);

                    break;
                }
            }

            throw new Failed('the measurement in the README is not what bin/measure.php prints — ' . $first);
        }
    },

    'the README says how many checks there are' => static function (): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');

        $running = 0;

        foreach (glob(check_root() . '/test/*.php') ?: [] as $file) {
            if (basename($file) === 'harness.php') {
                continue;
            }

            $running += count((array) require $file);
        }

        // The figure beside the command that runs them, and not any other
        // number of checks the file happens to mention.
        check_same(
            1,
            preg_match('/#\s*(\d+) checks/', $readme, $said),
            'the README no longer says how many checks the runner runs, so nothing was compared',
        );

        check_same($running, (int) $said[1], 'the README says ' . $said[1] . ' checks and there are ' . $running);
    },
];
