<?php declare(strict_types=1);

namespace Plugin\CommerceML;

use Alksily\Support\Crypta;
use App\Domain\AbstractPlugin;
use Psr\Container\ContainerInterface;
use Slim\Http\Request;
use Slim\Http\Response;

class CommerceMLPlugin extends AbstractPlugin
{
    const NAME = 'CommerceMLPlugin';
    const TITLE = 'CommerceML';
    const DESCRIPTION = 'Плагин для реализации интеграции с 1С посредством CommerceML 3';
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://site.0x12f.com';
    const VERSION = '1.0';

    protected const COOKIE_NAME = 'WSE_CML';
    protected const COOKIE_TIME = 10; // minutes
    protected const MAX_FILE_SIZE = 100 * 1000 * 1000; // bytes

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->addSettingsField([
            'label' => 'Включение и выключение Сommerce ML',
            'type' => 'select',
            'name' => 'is_enabled',
            'args' => [
                'option' => [
                    'off' => 'Выключена',
                    'on' => 'Включена',
                ],
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Имя пользователя',
            'description' => 'Для авторизации по протоколу Сommerce ML',
            'type' => 'text',
            'name' => 'login',
            'args' => [
                'placeholder' => 'Administrator',
                'value' => 'Administrator',
            ],
        ]);
        $this->addSettingsField([
            'label' => 'Пароль',
            'type' => 'text',
            'name' => 'password',
            'args' => [
                'placeholder' => 'Пароль пользователя',
                'force-value' => $this->parameter('CommerceMLPlugin_password', false) === false ? uniqid() : null,
            ],
        ]);

        // api для обмена
        $this
            ->map([
                'methods' => ['get'],
                'pattern' => '/api/cml',
                'handler' => fn($req, $res) => $this->action($req, $res),
            ])
            ->setName('api:cml');
    }

    protected function action(Request $request, Response $response)
    {
        $this->logger->debug('1c', [
            'method' => $request->getMethod(),
            'auth' => $request->getUri()->getAuthority(),
            'cookie' => $request->getCookieParams(),
            'post' => $request->getParams(),
        ]);

        if ($this->isSecure($request)) {
            switch ($request->getParam('type')) {
                case 'catalog':
                    switch ($request->getParam('mode')) {
                        case 'checkauth':
                            return $this->respondWithText($response, ['success', self::COOKIE_NAME, $this->getCookieValue()]);

                        case 'init':
                            return $this->respondWithText($response, ['zip=no', 'file_limit=' . self::MAX_FILE_SIZE]);

                        case 'file':
                            if (($file = $this->getFileFromBody($request->getParam('filename', 'import.xml'))) !== null) {
                                return $this->respondWithText($response, 'success');
                            }

                            return $this->respondWithText($response, 'failed');

                        case 'import':
                            if (($filename = $request->getParam('filename')) !== null) {
                                // write filename to tmp file
                                file_put_contents(VAR_DIR . '/cml.txt', str_replace('.xml', '', $filename) . PHP_EOL, FILE_APPEND);

                                return $this->respondWithText($response, 'success');
                            }

                            return $this->respondWithText($response, 'failed');

                        case 'complete':
                            if (($files = file_get_contents(VAR_DIR . '/cml.txt')) !== false) {
                                // remove tmp file
                                unlink(VAR_DIR . '/cml.txt');

                                // add import task
                                $task = new \Plugin\CommerceML\Tasks\ImportCMLTask($this->container);
                                $task->execute(['files' => explode(PHP_EOL, trim($files))]);

                                // run worker
                                \App\Domain\AbstractTask::worker($task);

                                return $this->respondWithText($response, 'success');
                            }

                            return $this->respondWithText($response, 'failed');
                    }

                    break;

                case 'sale':

                    break;
            }

            return $this->respondWithText($response, 'wrong method')->withStatus(405);
        }

        return $this->respondWithText($response, 'forbidden')->withStatus(403);
    }

    // check user info or cookie value
    protected function isSecure(Request $request): bool
    {
        $userInfo = implode(':', [$this->parameter('CommerceMLPlugin_login', ''), $this->parameter('CommerceMLPlugin_password', '')]);

        return (
                $request->getUri()->getUserInfo() === $userInfo ||
                $this->checkCookieIsAlive($request)
            ) &&
            $this->parameter('CommerceMLPlugin_is_enabled', 'off') === 'on';
    }

    // get cookie secure value
    protected function getCookieValue(): string
    {
        return Crypta::encrypt('' . time());
    }

    // check cookie is alive
    protected function checkCookieIsAlive(Request $request): bool
    {
        $value = $request->getCookieParam(self::COOKIE_NAME);

        if ($value) {
            return (time() - Crypta::decrypt($value)) / 60 <= self::COOKIE_TIME;
        }

        return false;
    }

    protected function respondWithText(Response $response, $output = ''): Response
    {
        if (is_array($output)) {
            $output = implode("\n", $output);
        }

        return $response->withHeader('Content-Type', 'text/plain')->write($output);
    }

    /** {@inheritdoc} */
    public function before(Request $request, Response $response, string $routeName): Response
    {
        return $response;
    }

    /** {@inheritdoc} */
    public function after(Request $request, Response $response, string $routeName): Response
    {
        return $response;
    }
}
