<?php

namespace Vigilance\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * Unwinds an exception's getPrevious() chain to its root cause.
 *
 * Wrappers lie about what broke. A Blade/Livewire ViewException is almost always
 * an envelope — the real fault ("Call to a member function … on null") lives three
 * layers down as the root Error, and its stack trace is where the offending line
 * actually is. Fingerprinting on the wrapper collapses two unrelated bugs that
 * happen to share a wrapper message; fingerprinting on the *root* keeps them apart
 * and keeps the same bug together even when the wrapping depth varies between
 * occurrences. So we identify a failure by its root, and render the whole chain
 * (root cause first, its frames intact) into one readable sample.
 */
class ExceptionChain
{
    /** Guard against pathological or self-referential chains. */
    protected const MAX_DEPTH = 10;

    /** Trace lines to keep for the root cause in the rendered sample. */
    protected const ROOT_FRAMES = 20;

    /**
     * @param  list<Throwable>  $links  Outermost wrapper first, root cause last.
     */
    protected function __construct(
        public readonly array $links,
        public readonly Throwable $root,
    ) {}

    public static function from(Throwable $e): self
    {
        $links = [];
        $seen = [];
        $current = $e;

        while ($current instanceof Throwable && count($links) < self::MAX_DEPTH) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break; // cycle guard — a chain that loops back on itself
            }
            $seen[$id] = true;

            $links[] = $current;
            $current = $current->getPrevious();
        }

        // The loop always runs once ($e is a Throwable), so $links is never empty
        // and its last element is the root cause.
        return new self($links, $links[count($links) - 1]);
    }

    /** The root cause's class — what the failure should be fingerprinted and named by. */
    public function rootClass(): string
    {
        return $this->root::class;
    }

    /** The root cause's message, de-noised (see cleanMessage), or null when empty. */
    public function rootMessage(): ?string
    {
        return static::cleanMessage($this->root->getMessage()) ?: null;
    }

    /** True when the exception was wrapped at least once (a previous exists). */
    public function isWrapped(): bool
    {
        return count($this->links) > 1;
    }

    /**
     * The application (non-vendor) frame that best explains the failure: the first
     * app frame walking the root cause's trace, then outward through the wrappers.
     * Falls back to the root's own throw location when the whole chain is vendor
     * code (still more useful than the wrapper's handleViewException frame 0).
     */
    public function culprit(): ?string
    {
        // Root cause first — its trace holds the offending app frame.
        foreach (array_reverse($this->links) as $link) {
            if ($app = CodeLocation::fromTrace($link->getTrace())) {
                return $app;
            }
        }

        return CodeLocation::relative($this->root->getFile()).':'.$this->root->getLine();
    }

    /**
     * Collapse a message's trailing run of repeated "(View: …)" fragments to their
     * unique set (order preserved). Blade re-wraps the same exception and Livewire
     * re-wraps that, each tacking on the same "(View: …)" — pure noise that also
     * destabilises the fingerprint the moment the wrapping depth varies between two
     * occurrences of one bug.
     */
    public static function cleanMessage(string $message): string
    {
        if (! preg_match('/^(.*?)((?:\s*\(View:[^)]*\))+)\s*$/s', $message, $m)) {
            return trim($message);
        }

        preg_match_all('/\(View:[^)]*\)/', $m[2], $views);
        $unique = array_values(array_unique($views[0]));

        return trim(rtrim($m[1]).($unique === [] ? '' : ' '.implode(' ', $unique)));
    }

    /**
     * Render the chain into one bounded, readable sample: the root cause with its
     * trace up front (that's where the fix is), then a compact "wrapped by" list of
     * the envelopes above it, then the promoted culprit line.
     */
    public function sample(int $max = 8000): string
    {
        $root = $this->root;
        $out = [];

        $out[] = '['.($this->isWrapped() ? 'root cause' : 'exception').'] '
            .$root::class.($this->rootMessage() !== null ? ': '.$this->rootMessage() : '');
        $out[] = 'at '.CodeLocation::relative($root->getFile()).':'.$root->getLine();

        $trace = preg_split('/\n/', $root->getTraceAsString()) ?: [];
        foreach (array_slice($trace, 0, self::ROOT_FRAMES) as $line) {
            $out[] = $line;
        }
        if (count($trace) > self::ROOT_FRAMES) {
            $out[] = '  … '.(count($trace) - self::ROOT_FRAMES).' more frame(s)';
        }

        if ($this->isWrapped()) {
            $out[] = '';
            $out[] = 'wrapped by (outermost first):';
            // Everything above the root cause — the envelopes, outermost first.
            foreach (array_slice($this->links, 0, -1) as $link) {
                $msg = static::cleanMessage($link->getMessage());
                $out[] = '  '.$link::class.($msg !== '' ? ': '.$msg : '')
                    .' ('.CodeLocation::relative($link->getFile()).':'.$link->getLine().')';
            }
        }

        if ($culprit = $this->culprit()) {
            $out[] = '';
            $out[] = 'culprit: '.$culprit;
        }

        return Str::limit(implode("\n", $out), $max);
    }
}
