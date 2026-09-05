<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Machine;

/**
 * State Machine عمومی (خالص، بدون WP).
 *
 * مطابق docs/state-machines/* — Transition: (from, event) → target(s) + Actors مجاز.
 *
 * یک (from, event) می‌تواند چند Candidate با Actors متفاوت داشته باشد:
 *   مثال: 'cancel' از confirmed: بیمار → cancelled_by_patient / کارکن → cancelled_by_staff
 *
 * قواعد Match:
 *  - Actor مشخص است: اولین Candidate که Actor در فهرستش باشد؛ اگر نبود،
 *    Candidate بدون محدودیت (فهرست خالی = همه) اگر موجود باشد.
 *  - Actor مشخص نیست (null): اولین Candidate بدون محدودیت، در غیر این صورت اولین Candidate.
 */
final class StateMachine
{
    /**
     * @var array<string, list<array{to: string, actors: list<string>}>>
     * کلید: "from|event"
     */
    private array $transitions = [];

    /** @var list<string> */
    private array $terminalStates = [];

    /** @var list<string> */
    private array $states = [];

    public function __construct(private readonly string $name)
    {
    }

    /**
     * @param list<string> $actors نقش‌های مجاز (خالی = همه)
     */
    public function addTransition(string $from, string $event, string $to, array $actors = []): self
    {
        $key = $from . '|' . $event;
        $this->transitions[$key][] = ['to' => $to, 'actors' => array_values($actors)];
        $this->states[] = $to;
        if ($from !== 'new') {
            $this->states[] = $from;
        }

        return $this;
    }

    public function addTerminal(string $state): self
    {
        $this->terminalStates[] = $state;

        return $this;
    }

    public function can(string $from, string $event, ?string $actor = null): bool
    {
        return $this->match($from, $event, $actor) !== null;
    }

    /**
     * @throws InvalidTransitionException
     *
     * @return string حالت مقصد
     */
    public function assert(string $from, string $event, ?string $actor = null): string
    {
        $matched = $this->match($from, $event, $actor);
        if ($matched === null) {
            $candidates = $this->transitions[$from . '|' . $event] ?? null;
            if ($candidates === null) {
                throw new InvalidTransitionException(
                    sprintf('%s: transition "%s" from "%s" is not allowed', $this->name, $event, $from)
                );
            }
            throw new InvalidTransitionException(
                sprintf('%s: actor "%s" may not perform "%s" from "%s"', $this->name, (string) $actor, $event, $from)
            );
        }

        return $matched['to'];
    }

    /**
     * @return array{to: string, actors: list<string>}|null
     */
    private function match(string $from, string $event, ?string $actor): ?array
    {
        $candidates = $this->transitions[$from . '|' . $event] ?? null;
        if ($candidates === null) {
            return null;
        }

        $unrestricted = null;
        foreach ($candidates as $c) {
            if ($c['actors'] === []) {
                $unrestricted = $unrestricted ?? $c;
                continue;
            }
            if ($actor !== null && in_array($actor, $c['actors'], true)) {
                return $c;
            }
        }

        if ($actor === null) {
            // بدون Actor: اول آزاد، در غیر این صورت اولین Candidate
            return $unrestricted ?? $candidates[0];
        }

        return $unrestricted;
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, $this->terminalStates, true);
    }

    /** @return list<string> */
    public function allowedEvents(string $from): array
    {
        $events = [];
        foreach (array_keys($this->transitions) as $key) {
            [$state, $event] = explode('|', $key, 2);
            if ($state === $from) {
                $events[] = $event;
            }
        }

        return array_values(array_unique($events));
    }

    /** @return list<string> */
    public function states(): array
    {
        return array_values(array_unique($this->states));
    }

    public function name(): string
    {
        return $this->name;
    }
}
