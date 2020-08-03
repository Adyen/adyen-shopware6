<?php

// app/src/Service/ContainerParametersHelper.php

namespace Adyen\Shopware\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ContainerParametersService
{

    /**
     * @var ParameterBagInterface
     */
    private $params;

    /**
     * ContainerParametersService constructor.
     *
     * @param ParameterBagInterface $params
     */
    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * This method returns the root directory.
     *
     * @return mixed
     */
    public function getApplicationRootDir()
    {
        return $this->params->get('kernel.project_dir');
    }

    /**
     * This method returns the value of the defined parameter.
     *
     * @param $parameterName
     * @return mixed
     */
    public function getParameter($parameterName)
    {
        return $this->params->get($parameterName);
    }
}
