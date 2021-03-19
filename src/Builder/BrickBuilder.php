<?php

declare(strict_types=1);

namespace Lle\CruditBundle\Builder;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Dto\BrickView;
use Lle\CruditBundle\Exception\UnsupportedBrickConfigurationException;
use Symfony\Component\HttpFoundation\Request;
use Lle\CruditBundle\Provider\BrickProvider;

class BrickBuilder
{

    /** @var BrickView[]  */
    private $bricks = [];

    /** @var BrickProvider  */
    private $brickProvider;

    public function __construct(
        BrickProvider $brickProvider
    ) {
        $this->brickProvider = $brickProvider;
    }

    /** @return BrickView[] */
    public function build(CrudConfigInterface $crudConfig, Request $request): array
    {
        foreach ($crudConfig->getBrickConfigs($request) as $brickConfig) {
            $brickConfig->setCrudConfig($crudConfig);
            $brickFactory = $this->brickProvider->getBrick($brickConfig);
            if ($brickFactory) {
                $this->bricks[] = $brickFactory->buildView($brickConfig);
            } else {
                throw new UnsupportedBrickConfigurationException(get_class($brickConfig));
            }
        }
        return $this->bricks;
    }

    public function getView(string $id): ?BrickView
    {
        return null;
    }

    public function bindRequest(Request $request): void
    {
    }
}
