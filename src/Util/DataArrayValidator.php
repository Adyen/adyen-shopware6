<?php

namespace Adyen\Shopware\Util;

class DataArrayValidator
{
    /**
     * Returns an array with only the approved keys
     *
     * @param array $array
     * @param array $approvedKeys
     * @return array
     */
    public static function getArrayOnlyWithApprovedKeys(array $array, array $approvedKeys): array
    {
        $result = array();

        foreach ($approvedKeys as $approvedKey) {
            if (isset($array[$approvedKey])) {
                $result[$approvedKey] = $array[$approvedKey];
            }
        }
        return $result;
    }
}
