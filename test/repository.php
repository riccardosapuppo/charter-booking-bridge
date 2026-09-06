<?php

declare(strict_types=1);

/**
 * Checks about the repository itself rather than about the bridge, so they say
 * the same thing whichever side is being run.
 */
return [
    'nothing here begins with a byte order mark' => static function (string $which): void {
        // Three bytes, before the opening tag, in a file WordPress includes on
        // every single request. They are output: they go out before any header
        // can, which is where "headers already sent" comes from, and they sit
        // in front of the body of every REST answer, where they stop the JSON
        // from parsing. The page's `await res.json()` throws, the visitor is
        // told the booking failed, and whether it happens at all depends on how
        // output buffering is configured — so it happens in production and not
        // on the laptop.
        //
        // The file this repository was rebuilt from began with them.
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

    'nothing here looks like an account, a person, or somebody\'s host' => static function (string $which): void {
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

    'every command the README gives is one that is here' => static function (string $which): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');

        preg_match_all('/^php (bin\/[a-z]+\.php)/m', $readme, $said);

        $named = array_unique($said[1]);

        check_true(
            count($named) >= 4,
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

    'the figures in the README are the ones the measurement prints' => static function (string $which): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');

        check_true($readme !== '', 'the README could not be read, so nothing was compared');

        // The claims table at the top of the README, which is prose and
        // therefore the part that goes stale without a sound.
        $table = [];

        if (preg_match('/It comes with (\w+) claims.*?\n\n(.*?)\n\n/s', $readme, $said) === 1) {
            $table = explode("\n", $said[2]);
        }

        check_true($table !== [], 'the README no longer has a claims table, so nothing was compared');

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
            'only ' . count($figures) . ' figures were found in the README claims table, so this check is looking in the wrong place',
        );

        $missing = [];

        foreach (array_unique($figures) as $figure) {
            if (!str_contains($printed, $figure)) {
                $missing[] = $figure;
            }
        }

        check_same([], $missing, 'the README quotes figures the measurement does not print: ' . implode(', ', $missing));
    },

    'the README says how many of them go red against the original' => static function (string $which): void {
        $readme = (string) file_get_contents(check_root() . '/README.md');
        $red = (string) file_get_contents(check_root() . '/bin/red.php');

        // The table in bin/red.php is the list of repairs somebody can watch
        // fail, and the README quotes its size in a line of output. A number in
        // a line of pasted output is a number that was true once.
        // The keys are on their own line, and three of them contain an escaped
        // apostrophe — which is how the first version of this check counted
        // fourteen of seventeen and was believed.
        $listed = preg_match_all('/^\s{4}\'(?:[^\'\\\\]|\\\\.)+\'\s*$/m', $red);

        check_true($listed > 10, 'only ' . $listed . ' checks were found in the red table, so this check is looking in the wrong place');

        check_same(
            1,
            preg_match('/(\d+) checks are meant to go red/', $readme, $said),
            'the README no longer says how many checks go red against the original',
        );

        check_same($listed, (int) $said[1], 'the README says ' . $said[1] . ' go red and the table lists ' . $listed);
    },

    'the README says how many checks there are' => static function (string $which): void {
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

