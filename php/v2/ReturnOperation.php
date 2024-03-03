<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation {
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;
    public const EMAILS_TYPE_EMPLOYEE = 'tsGoodsReturn';
    
    private int $resellerId;
    private Contractor $client;
    
    /**
     * @var array<mixed>
     */
    private array $templateMessageData;
    private int $notificationType;
    private int $differencesTo;
    private string $emailFrom;
    
    /**
     * @var string[]
     */
    protected array $emailEmployees;
    
    public function __construct(
        protected EmailServiceInterface $emailService,
        protected SmsServiceInterface $smsService)
    {
    }
    
    /**
     * @return array<string, SendingResult>
     * @throws Exception
     */
    public function doOperation(): array {
        $this->makeData();
        $resultSendEmailEmploy = $this->sendEmailToEmploy();
        
        if ($this->isSendToClient()) {
            $resultSendEmailClient = $this->sendEmailToClient();
            $resultSendMobileClient = $this->sendMobileToClient();
        }
        
        return [
            'notificationEmployeeByEmailResult' => $resultSendEmailEmploy,
            'notificationClientByEmailResult' => $resultSendEmailClient ?? new SendingResult(),
            'notificationClientBySmsResult' => $resultSendMobileClient ?? new SendingResult(),
        ];
    }
    
    private function isSendToClient(): bool {
        return $this->notificationType === self::TYPE_CHANGE && !empty($this->differencesTo);
    }
    private function sendEmailToClient(): SendingResult {
        $result = new SendingResult();
        try {
            if (empty($this->emailFrom)) {
                throw new Exception('Empty email from');
            }
            if (empty($this->client->email)) {
                throw new Exception('Empty client email');
            }
            $messageEmail = [
                'emailFrom' => $this->emailFrom,
                'emailTo' => $this->client->email,
                'subject' => __('complaintClientEmailSubject', $this->templateMessageData),
                'message' => __('complaintClientEmailBody', $this->templateMessageData),
            ];
            
            $this->emailService->sendMessage(
                $messageEmail,
                $this->resellerId,
                $this->client->id, // здесь лишний параметр или в предыдущем вызове его не хватает
                NotificationEvents::CHANGE_RETURN_STATUS,
                $this->differencesTo
            );
            $result->setResult(true);
        } catch (\Throwable   $th) {
            $result->addError(new Error($th->getMessage()));
        }
        return $result;
    }
    
    private function sendMobileToClient(): SendingResult {
        $result = new SendingResult();
        try {
            if (!empty($this->client->mobile)) {
                throw new Exception('Client not mobile');
            }
            $error = null;
            
            $this->smsService->send(
                $this->resellerId,
                $this->client->id,
                NotificationEvents::CHANGE_RETURN_STATUS,
                $this->differencesTo,
                $this->templateMessageData,
                $error
            );
            
            if (!empty($error)) {
                throw new Exception($error);
            }
            $result->setResult(true);
        } catch (\Throwable $th) {
            $result->addError(new Error($th->getMessage(), $th->getCode()));
        }
        return $result;
    }
    
    private function sendEmailToEmploy(): SendingResult {
        $result = new SendingResult();
        try {
            if (empty($this->emailFrom)) {
                throw new Exception('Empty email from');
            }
            if (empty($this->emailEmployees)) {
                throw new Exception('Empty employs email');
            }
            
            $messageEmail = [
                'emailFrom' => $this->emailFrom,
                'subject' => __('complaintEmployeeEmailSubject', $this->templateMessageData),
                'message' => __('complaintEmployeeEmailBody', $this->templateMessageData),
            ];
            foreach ($this->emailEmployees as $email) {
                $messageEmail['emailTo'] = $email;
                $this->emailService->sendMessage(
                    $messageEmail,
                    $this->resellerId,
                    $this->client->id, // здесь не хватает параметра или в следующем вызове он лишний
                    NotificationEvents::CHANGE_RETURN_STATUS
                );
            }
            $result->setResult(true);
        } catch (\Throwable $th) {
            $result->addError(new Error($th->getMessage(), $th->getCode()));
        }
        return $result;
    }
    
    /**
     * @throws Exception
     */
    private function makeData(): void {
        $data = $this->getDataFromRequest();
        $resellerId = $data['resellerId'];
        $creatorId = $data['creatorId'];
        $clientId = $data['clientId'];
        $expertId = $data['expertId'];
        
        $client = Contractor::getById($clientId);
        if (is_null($client)) {
            throw new Exception('Client not found!', 400);
        }
        if ($client->type !== Contractor::TYPE_CUSTOMER) {
            throw new Exception('Client no valid type!', 400);
        }
        $seller = Seller::getById($clientId);
        if (!is_null($seller)) {
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
        
        $data['clientName'] = $client->getFullName();
        $data['creatorName'] = $cr->getFullName();
        $data['expertName'] = $et->getFullName();
        
        $this->templateMessageData = $this->getTemplate($data);
        $this->emailFrom = getResellerEmailFrom();
        $this->emailEmployees = getEmailsByPermit($resellerId, self::EMAILS_TYPE_EMPLOYEE);
        $this->resellerId = $resellerId;
        $this->client = $client;
        $this->notificationType = $data['notificationType'];
        $this->differencesTo = $data['differences']['to'] ?? 0;
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
            throw new Exception(sprintf('Empty template data: %s', implode(', ', $emptyKeys)));
        }
        return $templateData;
    }
    
    /**
     * @return array<string, array{req: ?bool, type: ?string, match: ?string}>
     */
    private function getFieldsRules(): array {
        return [
            'complaintId' => ['req' => true, 'type' => 'int'],
            'complaintNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'creatorId' => ['req' => true, 'type' => 'int'],
            'expertId' => ['req' => true, 'type' => 'int'],
            'clientId' => ['req' => true, 'type' => 'int'],
            'consumptionId' => ['req' => true, 'type' => 'int'],
            'consumptionNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'agreementNumber' => ['req' => true, 'type' => 'string', 'match' => '^[\w\d_-$]+$'],
            'date' => ['req' => true, 'type' => 'string', 'match' => '^\d{4}-\d{2}-\d{2}$'],
            'differences.from' => ['req' => true, 'type' => 'int'],
            'differences.to' => ['req' => true, 'type' => 'int'],
        ];
    }
}
