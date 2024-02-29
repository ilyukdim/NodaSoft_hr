<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation {
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    public const EMAILS_TYPE_PERMITTED = 'tsGoodsReturn';
    
    /**
     * @throws Exception
     */
    public function doOperation(): array {
        $notificationEmployeeByEmail = false;
        $notificationClientByEmail = false;
        $notificationClientBySmsIsSent = false;
        $sendMobileErrMessage = '';
        
        $data = $this->getDataFromRequest();
        $resellerId = $data['resellerId'];
        $creatorId = $data['creatorId'];
        $clientId = $data['clientId'];
        $expertId = $data['expertId'];
        $notificationType = $data['notificationType'];
        
        $client = Contractor::getById($clientId);
        if (is_null($client)) {
            throw new Exception('Client not found!', 400);
        }
        if ($client->type !== Contractor::TYPE_CUSTOMER) {
            throw new Exception('Client no valid type!', 400);
        }
        $seller = Seller::getById($clientId);
        if(!is_null($seller)){
            throw new Exception('The client is a seller!', 400);
        }
        $cr = Employee::getById($creatorId);
        if (is_null($cr)) {
            throw new Exception('Creator not found!', 400);
        }
        $et = Employee::getById($expertId);
        if (is_null($et)) {
            throw new Exception('Expert not found!', 400);
        }
        
        $data['creatorName'] = $cr->getFullName();
        $data['expertName'] = $et->getFullName();
        $templateData = $this->getTemplate($data);
    
        $emailFrom = getResellerEmailFrom();
        $emails = getEmailsByPermit($resellerId, self::EMAILS_TYPE_PERMITTED);
        
        if (!empty($emailFrom) && count($emails) > 0) {
            $messageEmail = [
                'emailFrom' => $emailFrom,
                'subject' => __('complaintEmployeeEmailSubject', $templateData),
                'message' => __('complaintEmployeeEmailBody', $templateData),
            ];
            foreach ($emails as $email) {
                $messageEmail['emailTo'] = $email;
                MessagesClient::sendMessage(
                    [MessageTypes::EMAIL => $messageEmail],
                    $resellerId,
                    $client->id, // здесь не хватает параметра или в следующем вызове он лишний
                    NotificationEvents::CHANGE_RETURN_STATUS
                );
            }
            $notificationEmployeeByEmail = true;
        }

        /** Шлём клиентское уведомление, только если произошла смена статуса */
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                $messageEmail = [
                    'emailFrom' => $emailFrom,
                    'emailTo' => $client->email,
                    'subject' => __('complaintClientEmailSubject', $templateData),
                    'message' => __('complaintClientEmailBody', $templateData),
                ];
                
                MessagesClient::sendMessage(
                    [MessageTypes::EMAIL => $messageEmail],
                    $resellerId,
                    $client->id, // здесь лишний параметр или в предыдущем вызове его не хватает
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    $data['differences']['to']
                );
                $notificationClientByEmail = true;
            }

            if (!empty($client->mobile)) {
                $error = null;
                $notificationClientBySmsIsSent = NotificationManager::send(
                    $resellerId,
                    $client->id,
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    $data['differences']['to'],
                    $templateData,
                    $error
                );
                
                if (!empty($error)) {
                    $sendMobileErrMessage = $error;
                }
            }
        }
        
        return [
            'notificationEmployeeByEmail' => $notificationEmployeeByEmail,
            'notificationClientByEmail' => $notificationClientByEmail,
            'notificationClientBySms' => [
                'isSent' => $notificationClientBySmsIsSent,
                'error' => $sendMobileErrMessage,
            ],
        ];
    }
    
    
    /**
     * @return array<mixed> $data
     * @throws Exception
     */
    private function getDataFromRequest(): array {
        $data = $this->getRequest('data');
        $validationData = new Validation($this->getFieldsRules(), $data);
        return $validationData->validate();
    }
    
    /**
     * @param array<mixed> $data
     * @return array<mixed>
     * @throws Exception
     */
    private function getTemplate(array $data): array {
        $differences = '';
        $notificationType = $data['notificationType'];
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded');
        } elseif ($notificationType === self::TYPE_CHANGE && isset($data['differences'])) {
            $from = Status::getName($data['differences']['from']);
            $to = Status::getName($data['differences']['to']);
            if (!empty($from) && !empty($to)) {
                $differences = __('PositionStatusHasChanged', [
                    'FROM' => $from,
                    'TO' => $to,
                ]);
            }
        }
        
        $templateData = [
            'COMPLAINT_ID' => $data['complaintId'],
            'COMPLAINT_NUMBER' => $data['complaintNumber'],
            'CREATOR_ID' => $data['creatorId'],
            'CREATOR_NAME' => $data['creatorName'],
            'EXPERT_ID' => $data['expertId'],
            'EXPERT_NAME' => $data['expertName'],
            'CLIENT_ID' => $data['clientId'],
            'CLIENT_NAME' => $data['clientName'],
            'CONSUMPTION_ID' => $data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER' => $data['agreementNumber'],
            'DATE' => $data['date'],
            'DIFFERENCES' => $differences,
        ];
        
        /** Если хоть одна переменная для шаблона не задана, то не отправляем уведомления */
        $emptyKeys = [];
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                $emptyKeys[] = $key;
            }
        }
        if ($emptyKeys) {
            throw new Exception(sprintf('Empty template data: %s', implode(', ', $emptyKeys)), 500);
        }
        return $templateData;
    }
    
    /**
     * @return array<string, array{req: ?bool,type: ?string, match: ?string}>
     */
    private function getFieldsRules(): array {
        return [
            'complaintId' => ['req' => true, 'type' => 'int'],
            'complaintNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'creatorId' => ['req' => true, 'type' => 'int'],
            'creatorName' => ['req' => true, 'type' => 'string'],
            'expertId' => ['req' => true, 'type' => 'int'],
            'expertName' => ['req' => true, 'type' => 'string'],
            'clientId' => ['req' => true, 'type' => 'int'],
            'clientName' => ['req' => true, 'type' => 'string'],
            'consumptionId' => ['req' => true, 'type' => 'int'],
            'consumptionNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'agreementNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'date' => ['req' => true, 'type' => 'string', 'match' => '^\d{4}-\d{2}-\d{2}$'],
            'differences.from' => ['req' => true, 'type' => 'int'],
            'differences.to' => ['req' => true, 'type' => 'int'],
        ];
    }
}
