<?php

    $className = "fondy";
    $paymentName = "Fondy";
 
    include "standalone.php";
 
    $objectTypesCollection = umiObjectTypesCollection::getInstance();
    $objectsCollection = umiObjectsCollection::getInstance();
    $parentTypeId = $objectTypesCollection->getTypeIdByGUID("emarket-payment");
    $internalTypeId = $objectTypesCollection->getTypeIdByGUID("emarket-paymenttype");
    $typeId = $objectTypesCollection->addType($parentTypeId, $paymentName);
 

    $internalObjectId = $objectsCollection->addObject($paymentName, $internalTypeId);
    $internalObject = $objectsCollection->getObject($internalObjectId);
    $internalObject->setValue("class_name", $className);
 
    $internalObject->setValue("payment_type_id", $typeId);
    $internalObject->setValue("payment_type_guid", "user-emarket-payment-" . $typeId);
    $internalObject->commit();
 
    $type = $objectTypesCollection->getType($typeId);
    $type->setGUID($internalObject->getValue("payment_type_guid"));
    $type->commit();
 
    echo "Ok";


?>