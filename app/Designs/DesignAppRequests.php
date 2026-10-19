<?php

namespace App\Designs;

use App\Http\Controllers\DesignAssetController;
use App\Http\Controllers\DesignCommentController;
use App\Http\Controllers\DesignCommentImageController;
use App\Http\Controllers\DesignEditController;
use App\Http\Controllers\DesignLiveController;
use App\Http\Controllers\DesignSystemController;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * O editor do canvas dentro do MCP App (sem cookie nem CSRF) chama as mesmas rotas
 * da página do design por aqui, com o usuário do token OAuth. Só as rotas do editor,
 * e cada controller faz a própria autorização (Gate), como na web.
 */
class DesignAppRequests
{
    private const UUID = '([0-9a-f]{32})';

    /**
     * [método, padrão do caminho, controller, ação, nomes dos parâmetros capturados]
     *
     * @var list<array{string, string, class-string, string, list<string>}>
     */
    private const ROUTES = [
        ['POST', '#^d/'.self::UUID.'/edit$#', DesignEditController::class, 'ops', ['artifact']],
        ['GET', '#^d/'.self::UUID.'/boards/([0-9]+)/source$#', DesignEditController::class, 'source', ['artifact', 'board']],
        ['POST', '#^d/'.self::UUID.'/comments$#', DesignCommentController::class, 'store', ['artifact']],
        ['PATCH', '#^d/'.self::UUID.'/comments/([0-9]+)$#', DesignCommentController::class, 'update', ['artifact', 'comment']],
        ['DELETE', '#^d/'.self::UUID.'/comments/([0-9]+)$#', DesignCommentController::class, 'destroy', ['artifact', 'comment']],
        ['POST', '#^d/'.self::UUID.'/view$#', DesignCommentController::class, 'view', ['artifact']],
        ['POST', '#^d/'.self::UUID.'/comment-images$#', DesignCommentImageController::class, 'store', ['artifact']],
        ['DELETE', '#^d/'.self::UUID.'/comment-images/([0-9]+)$#', DesignCommentImageController::class, 'destroy', ['artifact', 'image']],
        ['GET', '#^d/'.self::UUID.'/assets$#', DesignAssetController::class, 'index', ['artifact']],
        ['POST', '#^d/'.self::UUID.'/assets$#', DesignAssetController::class, 'store', ['artifact']],
        ['DELETE', '#^d/'.self::UUID.'/assets/([a-z0-9.-]+)$#', DesignAssetController::class, 'destroy', ['artifact', 'name']],
        ['GET', '#^d/'.self::UUID.'/live$#', DesignLiveController::class, '__invoke', ['artifact']],
        ['GET', '#^design-systems/search$#', DesignSystemController::class, 'search', []],
    ];

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: mixed}
     */
    public function dispatch(User $user, string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);
        $parts = parse_url('/'.ltrim($path, '/'));
        $route = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach (self::ROUTES as [$verb, $pattern, $controller, $action, $names]) {
            if ($verb !== $method || ! preg_match($pattern, $route, $match)) {
                continue;
            }

            $params = array_combine($names, array_slice($match, 1)) ?: [];

            return $this->call($user, $method, '/'.$route, $query, $body, $controller, $action, $params);
        }

        return ['status' => 404, 'body' => ['message' => 'Ação do editor desconhecida.']];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  class-string  $controller
     * @param  array<string, string>  $params
     * @return array{status: int, body: mixed}
     */
    private function call(User $user, string $method, string $uri, array $query, array $body, string $controller, string $action, array $params): array
    {
        $files = [];
        if (isset($body['file']) && is_array($body['file'])) {
            $files['file'] = $this->upload($body['file']);
            unset($body['file']);
        }

        $request = Request::create($uri, $method, $method === 'GET' ? $query : $body, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        $request->query->add($query);
        $request->setUserResolver(fn () => $user);

        $previous = app('request');
        app()->instance('request', $request);

        try {
            $response = app()->call([app($controller), $action], ['request' => $request, ...$params]);

            return $this->result($response);
        } catch (ValidationException $e) {
            return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
        } catch (AuthorizationException $e) {
            return ['status' => 403, 'body' => ['message' => $e->getMessage() ?: 'Sem permissão.']];
        } catch (ModelNotFoundException) {
            return ['status' => 404, 'body' => ['message' => 'Não encontrado.']];
        } catch (HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage()]];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 500, 'body' => ['message' => 'Erro no servidor.']];
        } finally {
            app()->instance('request', $previous);
            foreach ($files as $file) {
                @unlink($file->getRealPath());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $file  {name, type, base64}
     */
    private function upload(array $file): UploadedFile
    {
        $bytes = base64_decode((string) ($file['base64'] ?? ''), true);
        if ($bytes === false) {
            throw ValidationException::withMessages(['file' => 'Arquivo inválido.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'ward-asset-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, basename((string) ($file['name'] ?? 'imagem')), (string) ($file['type'] ?? '') ?: null, null, true);
    }

    /**
     * @return array{status: int, body: mixed}
     */
    private function result(mixed $response): array
    {
        if ($response instanceof JsonResponse) {
            return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
        }

        if ($response instanceof SymfonyResponse) {
            $content = (string) $response->getContent();
            $decoded = json_decode($content, true);

            return ['status' => $response->getStatusCode(), 'body' => $decoded ?? ($content === '' ? null : $content)];
        }

        return ['status' => 200, 'body' => $response];
    }
}
