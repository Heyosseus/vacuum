<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;

/**
 * One lesson, in four bands: the prose, what the lesson found in this
 * database, what to do about it (when there is a fork), and a statement the
 * reader can go run themselves.
 *
 * The slug arrives from the URL and is therefore untrusted. It is used only
 * to select a registered lesson; the Blade partial the shell includes is
 * built from the slug the resolved lesson reports for itself, never from the
 * string the request supplied, so no request can name a template.
 */
final readonly class LessonController
{
    public function __construct(
        private Curriculum $curriculum,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(string $lesson): View
    {
        $chosen = $this->curriculum->find($lesson);

        if (! $chosen instanceof Lesson) {
            abort(404);
        }

        [$previous, $next] = $this->neighbours($chosen);

        $after = $chosen->after();

        return Views::make('vacuum::learn.lesson', [
            'lesson' => $chosen,
            'observation' => $chosen->observe(),
            'tryIt' => $chosen->tryIt(),
            'tree' => $chosen->tree(),
            // Resolved here rather than in the view: a slug is not a link, and a
            // template is the wrong place to go looking one up.
            'after' => $after === null ? null : $this->curriculum->find($after),
            'previous' => $previous,
            'next' => $next,
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }

    /**
     * The lessons either side of this one in the curriculum's own order, so the
     * footer can offer a reader the next thing to read rather than send them
     * back to the index to find it.
     *
     * @return array{0: ?Lesson, 1: ?Lesson}
     */
    private function neighbours(Lesson $chosen): array
    {
        $lessons = $this->curriculum->all();

        $previous = null;
        $next = null;

        foreach ($lessons as $at => $lesson) {
            if ($lesson->slug() === $chosen->slug()) {
                $previous = $lessons[$at - 1] ?? null;
                $next = $lessons[$at + 1] ?? null;
            }
        }

        return [$previous, $next];
    }
}
