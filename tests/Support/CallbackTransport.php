<?php

namespace Modules\Sviat\Meest\Support;

use Okay\Modules\Sviat\Meest\Api\Credentials;
use Okay\Modules\Sviat\Meest\Api\Http\HttpRequest;
use Okay\Modules\Sviat\Meest\Api\Http\HttpResponse;
use Okay\Modules\Sviat\Meest\Api\Http\HttpTransport;

/** Відповідає функцією від шляху запиту: тест описує стан Meest, а не порядок запитів. */
final class CallbackTransport implements HttpTransport
{
    /** @var list<string> */
    public array $paths = [];
    /** @var list<int> */
    public array $timeouts = [];

    /** @param \Closure(string): HttpResponse $answer */
    public function __construct(private readonly \Closure $answer)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $path = str_starts_with($request->url, Credentials::BASE) ? substr($request->url, strlen(Credentials::BASE)) : $request->url;
        $this->paths[] = $path;
        $this->timeouts[] = $request->timeoutSeconds;

        return ($this->answer)($path);
    }

    /** @return list<string> */
    public function pathsStartingWith(string $prefix): array
    {
        return array_values(array_filter($this->paths, static fn(string $p): bool => str_starts_with($p, $prefix)));
    }
}
