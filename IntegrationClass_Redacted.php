<?php

namespace AdminPortal\Classes;

use \Exception;
use AdminPortal\Classes\Validator;
use AdminPortal\Classes\APIException;
use AdminPortal\Services\Util;
use AdminPortal\Services\ReportService;

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;
use Aws\S3\Command;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7;

use AdminPortal\Classes\EmailHelper;
use Postmark\Models\PostmarkException;
use Postmark\Models\PostmarkAttachment;
use \SimpleXMLElement;

// Class available through PSR0 composer config file from other files (cannot share the contents)
use Subdomain;

use Dompdf\Dompdf;

$pdoObj = $GLOBALS['PDO_OBJ'];

class CSCIntegration {
	private $ticketId;
	private $companyId;
	private $submissionData;
	private $fileData;
	public $hasAttachment = false;
	public $xmlParams = [];

	public function __construct($companyId, $ticketId = NULL) {
		$this->setCompanyId($companyId);
		$this->setTicketId($ticketId);
		$this->setSCSBaseUrl();
		$this->setCompanyDomain();
		$this->setSocketServer();
	}

	public function tester() {
		return $this->getSubmissionData();
	}

	private function getFields($formFields) {
		$fields = array();
		foreach ($formFields['subFields'] as $key => $field) {
			if ($field['FieldName'] == 'Attachment') {
				// if Attachement find fieldLabel in supporting Documents
				foreach ($formFields['supportingDocuments'] as $key => $docArr) {
					if (array_key_exists($field['FieldLabel'], $docArr)) {
						$searchId = $docArr[$field['FieldLabel']];
						foreach ($formFields['filesPreview'] as $key => $fileData) {
							if ($fileData['fileId'] == $searchId) {
								$fieldLabel = trim(substr($field['FieldLabel'], 0, 4)) . '_Attachment';
								$fields[$fieldLabel] = array('attachType' => $field['FieldLabel'], 'fileId' => $fileData['fileId'], 'attachment' => $fileData['filePreview']);
								break;
							}
						}
						break;
					}
				}
			} else {
				$fields[$field['FieldId']] = $field;
			}
		}

		return $fields;
	}

	private function setMainFile($fileId) {
		global $pdoObj;

		$statement = $pdoObj->prepare('UPDATE submission_files SET isMainFile = 1, isAttachment = 0 WHERE subFileId = :id');
		$statement->execute([':id' => $fileId]);
	}

	private function getParamsFromForm($submissionData, $formFields, $fileId) {
		$fileData = $this->getFileData($fileId);

		// set main file
		$this->setMainFile($fileId);

		// parse & format form data
		$fieldIds = $this->getFields($formFields);
		$fieldKeys = array_keys($fieldIds);
		$filesList = array();
		$filesList[] = $fileId;
		$permaFields = array(
			'username' => $this->getCSCUsername(),
			'password' => $this->getCSCPass(),
			'domain' => $this->getCSCDomain(),
			'packageID' => $submissionData['submissionId'],
			'UID' => $submissionData['submissionId'],
			'documentType' => $formFields['docType']['FieldValue'],
			'county' => $submissionData['county'],
			'state' => $submissionData['stateSubmission'],
			'document' => $this->getFileContent($fileData),
			'attachments' => array()
		);

		$params = array(
		  'permaFields' => $permaFields,
		  'requiredFields' => array()
		 );

		foreach ($fieldIds as $key => $fieldArr) {
			if (array_key_exists('FieldName', $fieldArr)) {
				$params['requiredFields'][$fieldArr['FieldName']] = $fieldArr['FieldValue'];
			}
		}

		foreach($fieldKeys as $index => $key) {
			if (preg_match('/Attachment/', $key)) {
				$params['permaFields']['attachments'][] = array(
				  'attachment' => $fieldIds[$key]['attachment'],
				  'attachType' => $fieldIds[$key]['attachType'],
				  'fileId' => $fieldIds[$key]['fileId']
				 );
				$filesList[] = $fieldIds[$key]['fileId'];
			}
		}

		if (count($params['permaFields']['attachments']) > 0) {
			// $params['attachments']
			$this->hasAttachment = true;
			foreach ($params['permaFields']['attachments'] as $key => $param) {
				$this->storeAttachmentFile($submissionData['submissionId'], $param, $param['attachType'], $param['fileId']);
			}

			$this->updateUnusedFiles($submissionData['submissionId'], $filesList);
		} else {
			$this->hasAttachment = false;
		}

		$this->xmlParams = $params;
		return $params;
	}

	private function updateUnusedFiles($ticketId, $filesList) {
		global $pdoObj;

		$list = join(',', $filesList);
		$query = 'UPDATE submission_files SET isAttachment = 0, isMainFile = 0 WHERE submissionId = :id AND subFileId NOT IN (' . $list . ')';

		$statement = $pdoObj->prepare($query);
		$statement->execute([':id' => $ticketId]);
	}

	private function storeAttachmentFile($ticketId, $fileData, $attachType, $fileId) {
		global $pdoObj;

		$statement = $pdoObj->prepare('INSERT INTO submission_attachments (subId, companyId, data, fileId, attachType, dateAdded) VALUES (?, ?, ?, ?, ?, NOW())');
		$statement->execute([$ticketId, $this->getCompanyId(), json_encode($fileData), $fileData['fileId'], $attachType]);

		$statement = $pdoObj->prepare('UPDATE customers_submissions SET hasAttachement = 1, subFileId = :file WHERE submissionId = :id');
		$statement->execute([':id' => $ticketId, ':file' => $fileId]);

		$statement = $pdoObj->prepare('UPDATE submission_files SET isAttachment = 1, isMainFile = 0 WHERE subFileId = :id');
		$statement->execute([':id' => $fileData['fileId']]);
	}

	public function transmitDocument($fileId, $formFields) {
		$companyId = $this->getCompanyId();
		$submissionData = $this->getSubmissionData();
		$params = $this->getParamsFromForm($submissionData, $formFields, $fileId);

		if (!!$this->hasAttachment) {
			$cscLoadXMLFile = json_decode(file_get_contents('loadXMLAttachment.json'), true);
		} else {
			$cscLoadXMLFile = json_decode(file_get_contents('loadXML.json'), true);
		}

		// fill in array into XML POST request format
		$xmlParams = $this->fillXML($cscLoadXMLFile['request'], $params, true);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->getResponseResults($result);

		if (!is_array($responseArr) || count($responseArr) < 0 || $responseArr['Batch']['@attributes']['status'] != "Success") {
			// return error message
			return array(
			  'status' => 'error',
			  'message' => 'An error occured when submitting your document. Please contact support.'
			 );
		} else {
			// check if document is NOT queued
			$statuses = array('Queued');
			if (!in_array($responseArr['Batch']['Documents']['Document']['@attributes']['status'], $statuses)) {
				// get status update of document then send notification
				return $this->getDocstatus($responseArr['Batch']['@attributes']['trackingID'], $submissionData['submissionId']);
			} else {
				$updatedStatus = $this->updateDocStatus($fileId, $responseArr['Batch']['Documents']['Document']['@attributes']['uid'], $submissionData['submissionId']);
				return array(
				  'status' => 'success',
				  'packageStatus' => $updatedStatus,
				  'trackingID' => $responseArr['Batch']['@attributes']['trackingID'],
				  'uid' => $responseArr['Batch']['Documents']['Document']['@attributes']['uid'],
				  'message' => $responseArr['Batch']['@attributes']['message'],
				  'dateTransmitted' => date('m/d/Y')
				 );
			}
		}
	}

	public function updateRecording ($fileId, $formFields, $xmlFields) {
		$companyId = $this->getCompanyId();
		$submissionData = $this->getSubmissionData();

		if (!!$this->hasAttachment) {
			$cscLoadXMLFile = json_decode(file_get_contents('loadXMLAttachment.json'), true);
		} else {
			$cscLoadXMLFile = json_decode(file_get_contents('loadXML.json'), true);
		}

		// update xml params
		$fileData = $formFields['fileUploaded']['FieldValue'];
		$xmlFields['document'] = $fileData;

		// fill in array into XML POST request format
		$xmlParams = $this->fillXML($cscLoadXMLFile['request'], $xmlFields, true);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->getResponseResults($result);

		if (!is_array($responseArr) || count($responseArr) < 0 || $responseArr['Batch']['@attributes']['status'] != "Success") {
			// return error message
			return array(
			  'status' => 'error',
			  'message' => 'An error occured when submitting your document. Please contact support.'
			 );
		} else {
			// check if document is NOT queued
			$statuses = array('Queued');
			if (!in_array($responseArr['Batch']['Documents']['Document']['@attributes']['status'], $statuses)) {
				// get status update of document then send notification
				return $this->getDocstatus($responseArr['Batch']['@attributes']['trackingID'], $submissionData['submissionId']);
			} else {
				$updatedStatus = $this->updateDocStatus($fileId, $responseArr['Batch']['Documents']['Document']['@attributes']['uid'], $submissionData['submissionId'], true);
				return array(
				  'status' => 'success',
				  'packageStatus' => $updatedStatus,
				  'trackingID' => $responseArr['Batch']['@attributes']['trackingID'],
				  'uid' => $responseArr['Batch']['Documents']['Document']['@attributes']['uid'],
				  'message' => $responseArr['Batch']['@attributes']['message'],
				  'dateTransmitted' => date('m/d/Y')
				 );
			}
		}
	}

	public function getDocstatus($trackingId, $ticketId) {
		$companyId = $this->getCompanyId();
		$submissionData = $this->getSubmissionData();
		$fileData = $this->getFileData($fileId);

		$params = array(
			'username' => $this->getCSCUsername(),
			'password' => $this->getCSCPass(),
			'domain' => $this->getCSCDomain(),
			'uid' => $ticketId . '-EB363'
		);

		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('docStatus.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseStatusResults($result);
		switch ($responseArr['Batch']['Documents']['Document']['@attributes']['status']) {
			case 'Recorded':
				// TODO: Handle data about file and recording returned
				return $this->storeRecordedInfo($trackingId, $responseArr['Batch']['Documents']['Document']);
				break;
			case 'Rejected':
				// TODO: Handle data about rejection reason
				return $this->storeRejectedInfo($fileId, $submissionData, $responseArr['Batch']['Documents']['Document']);
				break;
			case 'Pending':
				return $this->storePendingInfo($fileId, $responseArr['Batch']['Documents']['Document']);
				break;
			default:
				// document ready to send
				return 'Ready To Send';
				break;
		}
	}

	public function updateDocStatus($fileId, $uid, $ticketId, $isUpdate = false) {
		$companyId = $this->getCompanyId();
		$submissionData = $this->getSubmissionData();
		// $fileData = $this->getFileData($fileId);

		$params = array(
			'permaFields' => array(
				'username' => $this->getCSCUsername(),
				'password' => $this->getCSCPass(),
				'domain' => $this->getCSCDomain(),
				'uid' => $uid
			)
		);

		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('docStatus.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseStatusResults($result);
		switch ($responseArr['Batch']['Documents']['Document']['@attributes']['status']) {
			case 'Recorded':
				// TODO: Handle data about file and recording returned
				return $this->storeRecordedInfo($responseArr['Batch']['Documents']['Document']);
				break;
			case 'Rejected':
				// TODO: Handle data about rejection reason
				return $this->storeRejectedInfo($fileId, $submissionData, $responseArr['Batch']['Documents']['Document']);
				break;
			case 'Pending':
				return $this->storePendingInfo($fileId, $responseArr['Batch']['Documents']['Document']);
				break;
			default:
				// document ready to send
				return 'Ready To Send';
				break;
		}
	}

	private function storeRejectedInfo($fileId, $submissionData, $documentArr) {
		global $pdoObj;

		$documentData = array(
		  'status' => $documentArr['@attributes']['status']
		 );

		foreach($documentArr['Details']["ReturnData"]["Data"]['Field'] as $key => $field) {
			if (is_array($field) && $field['@attributes']['Name'] == 'FeeDetails') {
				$documentData['fee'] = number_format($field['Fee']['@attributes']['Amount'], 2);
			}
		}

		$documentData['county'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][0];
		$documentData['state'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][1];
		$documentData['uid'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][2];
		$documentData['documentType'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][3];
		$documentData['rejectionReason'] = $documentArr['Details']['ReturnData']['Data']['Field'][4];
		$documentData['time'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][5];

		// TODO: USER ARRAY ABOVE RATHER THAN FEES
		$documentData['fee'] = number_format($submissionData['estimatedFees'], 2);

		// buid PDF rejection reason
		$fileName = $documentArr['@attributes']['uid'] . '_Rejection_response.pdf';
		$documentData['fileName'] = $fileName;
		return $documentData;
	}

	private function storePendingInfo($fileId, $documentArr) {
		$documentData = array(
		  'status' => 'Pending',
		  'avgTime' => 0.00
		 );
	}

	private function storeRecordedInfo($documentArr) {
		global $pdoObj;
		$provider = CredentialProvider::defaultProvider();
		$documentData = array(
		  'status' => $documentArr['@attributes']['status']
		 );


		foreach($documentArr['Details']["ReturnData"]["Data"]['Field'] as $key => $field) {
			if (is_array($field) && $field['@attributes']['Name'] == 'FeeDetails') {
				$documentData['fee'] = number_format($field['Fee']['@attributes']['Amount'], 2);
			}
		}

		$fileName = $documentArr['@attributes']['uid'] . 'Recorded_Response.pdf';

		$documentData['county'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][0];
		$documentData['state'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][1];
		$documentData['documentType'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][3];
		$documentData['date'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][4];
		$documentData['time'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][5];
		$documentData['recordationNumber'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][6]; //238657
		$documentData['book'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][7]; //123
		$documentData['page'] = $documentArr['Details']["ReturnData"]["Data"]['Field'][8]; //456
		$documentData['fileName'] = $fileName;
		$documentData['uid'] = $documentArr['@attributes']['uid'];
		$documentData['trackingID'] = $documentArr['Details']["ReturnData"]['Data']['@attributes']['UID'];
		$documentData['packageStatus'] = $documentArr['@attributes']['status'];

		$encodedDoc = $documentArr['Details']["ReturnData"]["Data"]["EMBEDDED_DOCUMENT"];
		$pdfDoc = base64_decode($encodedDoc);

		// store file to S3 response
		$s3Client = new S3Client([
		  'region' => 'us-east-1',
		  'version' => 'latest',
		  'credentials' => $provider
		 ]);

		$result = $s3Client->putObject(array(
			'Bucket' => 'customer-submissions',
			'Key' => $this->getCompanyDomain() . '/csc/responses/' . $fileName,
			'Body' => $pdfDoc
		));

		$documentData['respFile'] = array(
		  'path' => $this->getCompanyDomain() . '/csc/responses/' . $fileName,
		 );

		return $documentData;
	}

	public function updateDBStatus($uid, $ticketId, $statusArr, $fileId, $hasAttachment = False) {
		global $pdoObj;

		if (!is_array($statusArr)) {
			// Ready to send
			$statement = $pdoObj->prepare('UPDATE csc_erecordings SET cscStatus = :status WHERE cscUID = :uid AND submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => $statusArr, ':uid' => $uid, ':id' => $ticketId, ':fileId' => $fileId]);

			// update second table
			$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => $statusArr, ':id' => $ticketId, ':fileId' => $fileId]);
		} else if ($statusArr['status'] == 'Rejected') {
			// Rejected
			$statement = $pdoObj->prepare('UPDATE csc_erecordings SET cscStatus = :status, docResponse = :docResp WHERE cscUID = :uid AND submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => $statusArr['status'], ':uid' => $uid, ':id' => $ticketId, ':docResp' => json_encode($statusArr), ':fileId' => $fileId]);

			// update second table
			$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => $statusArr['status'], ':id' => $ticketId, ':fileId' => $fileId]);
		} else if ($statusArr['status'] == 'Recorded'){
			// Approved
			$statement = $pdoObj->prepare('UPDATE csc_erecordings SET cscStatus = :status, cscDocPath = :cscPath, docResponse = :docResp, cscFileName = :fileName WHERE cscUID = :uid AND submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => $statusArr['status'], ':uid' => $uid, ':id' => $ticketId, ':cscPath' => $statusArr['respFile']['path'], ':docResp' => json_encode($statusArr), ':fileName' => $statusArr['fileName'], ':fileId' => $fileId]);

			// update second table
			// update attachments if any
			if (!$hasAttachment) {
				$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id AND subFileId = :fileId');
				$statement->execute([':status' => $statusArr['status'], ':id' => $ticketId, ':fileId' => $fileId]);
			} else {
				$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id');
				$statement->execute([':status' => $statusArr['status'], ':id' => $ticketId]);
			}
		} else {
			// Pending
			$statement = $pdoObj->prepare('UPDATE csc_erecordings SET cscStatus = :status, cscAvgTime = :avgTime WHERE cscUID = :uid AND submissionId = :id AND subFileId = :fileId');
			$statement->execute([':status' => 'Pending', ':uid' => $uid, ':id' => $ticketId, ':avgTime' => $statusArr['avgTime'], ':fileId' => $fileId]);

			// update second table
			if (!$hasAttachment) {
				$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id AND subFileId = :fileId');
				$statement->execute([':status' => 'Pending', ':id' => $ticketId, ':fileId' => $fileId]);
			} else {
				$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE submissionId = :id');
				$statement->execute([':status' => 'Pending', ':id' => $ticketId]);
			}
		}

		// update invoice status to empty
		$statement = $pdoObj->prepare('UPDATE customers_submissions SET invoiceStatus = "-" WHERE submissionId = :id AND companyId = :company');
		$statement->execute([':id' => $ticketId, ':company' => $this->getCompanyId()]);
	}

	public function getAvailableCounties($state) {
		$params = array(
			'username' => $this->getCRQUsername(),
			'password' => $this->getCRQPass(),
			'state' => $state
		);


		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('availableCounties.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml", "SOAPAction: \"\""));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseCountiesResults($result);

		return $responseArr;
	}

	public function addAcceptedDocTypes($state, $county) {
		global $pdoObj;
		$companyId = $this->getCompanyId();

		$params = array(
			'username' => $this->getCRQUsername(),
			'password' => $this->getCRQPass(),
			'county' => $county,
			'state' => $state
		);

		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('countyDocTypes.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml", "SOAPAction: \"\""));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseCountyAcceptedDocs($result);

		foreach ($responseArr as $key => $document) {
			$statement = $pdoObj->prepare('INSERT INTO csc_counties_docs (state, county, docLevel, docType, docCode, efileEnabled, dateUpdated, dateAdded) VALUES (?, ?, ?, ? , ?, ?, ?, NOW())');
			$statement->execute([$state, $county, $document['DocumentLevel'], $document['DocumentType'], $document['DocumentCode'], $document['efileable'], $document['ModifiedDate']]);
		}

		return 'County Docs for $county have been added';
	}

	public function addDocRequirements($state, $county, $docType, $docLevel) {
		global $pdoObj;

		$params = array(
			'username' => $this->getCRQUsername(),
			'password' => $this->getCRQPass(),
			'docLevel' => $docLevel,
			'state' => $state,
			'county' => $county,
			'docType' => $docType
		);


		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('docRequirements.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml", "SOAPAction: \"\""));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseDocRequirements($result);

		$statement = $pdoObj->prepare('INSERT INTO csc_counties_docs_requirements (state, county, docType, docLevel, jsonField, dateAdded) VALUES (?, ?, ?, ?, ?, NOW())');
		$statement->execute([$state, $county, $docType, $docLevel, $responseArr]);

		return 'Doc requirements for $county $docType have been added';
	}

	public function getDocRequirements($state, $county, $docType, $docLevel) {
		global $pdoObj;

		$params = array(
			'username' => $this->getCRQUsername(),
			'password' => $this->getCRQPass(),
			'docLevel' => $docLevel,
			'state' => $state,
			'county' => $county,
			'docType' => $docType
		);

		// fill in array into XML POST request format
		$cscDocStatusFile = json_decode(file_get_contents('docRequirements.json'), true);
		$xmlParams = $this->fillXML($cscDocStatusFile['request'], $params);

		$apiUrl = "";

		// make POST curl request with XML
		$call = curl_init();
		curl_setopt($call, CURLOPT_URL, $apiUrl);
		curl_setopt ($call, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml", "SOAPAction: \"\""));

		curl_setopt($call, CURLOPT_POST, 1);
		curl_setopt($call, CURLOPT_POSTFIELDS, $xmlParams);
		curl_setopt($call, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($call);

		$responseArr = $this->parseDocRequirements($result);

		return $responseArr;
	}

	public function sendLiveNotification($ticketId, $Substatus) {
		// send APICall
		$ch = curl_init();
		$notificationArr = array(
		  'ticketId' => $ticketId,
		  'status' => $Substatus
		 );

		// get domain site for socket pointer
		$socketURL = $this->getSocketServer() . '/updatestatus';

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_URL, $socketURL);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notificationArr));
		curl_exec($ch);
		curl_close($ch);

		return 'Sent Live notification successfully.';
	}

	public function setSocketServer() {
		global $_pdoDB;

		$statement = $_pdoDB->prepare('SELECT socketUrl FROM companies WHERE companyId = :id AND socketOn = 1');
		$statement->execute([':id' => $this->getCompanyId()]);
		$response = $statement->fetch();

		$this->socketURL = $response['socketUrl'];
	}

	public function parseDocRequirements($res) {
		$requirements = array();
		$regex = '/<Requirement>(.*?)<\/Requirement>/i';
		preg_match_all($regex, $res, $matches);

		foreach ($matches[1] as $key => $match) {
			$xmlObj = simplexml_load_string('<root>' . $match . '</root>');
			$xmlJson = json_encode($xmlObj);
			$xmlArr = json_decode($xmlJson, true);

			$requirements[] = array(
				'FieldLabel' => $xmlArr['Name'],
				'FieldName' => $xmlArr['Mapping'],
				'FieldId' => $xmlArr['Mapping'],
				'required' => $xmlArr['Description'] == "Required" ? true : false
			 );

			if ($xmlArr['Mapping'] == 'Attachment') {
				$requirements[0]['attachment'] = true;
			} else {
				$requirements[0]['attachment'] = false;
			}
		}

		return json_encode($requirements);
	}

	public function parseCountyAcceptedDocs($res) {
		$countyDocs = array();
		$regex = '/<DocType>(.*?)<\/DocType>/i';
		preg_match_all($regex, $res, $matches);

		foreach ($matches[1] as $key => $match) {
			$xmlObj = simplexml_load_string('<root>' . $match . '</root>');
			$xmlJson = json_encode($xmlObj);
			$countyDocs[] = json_decode($xmlJson, true);
		}

		return $countyDocs;
	}

	public function parseCountiesResults($res) {
		$regex = '/<string>(.*?)<\/string>/';
		preg_match_all($regex, $res, $matches);

		return $matches[1];
	}

	public function parseStatusResults($res) {
		$regex = '/\<(GetDocStatusPDFResult|GetAcceptedDocTypesResult)\>(.*)\<\/(GetDocStatusPDFResult|GetAcceptedDocTypesResult)\>/i';
		preg_match($regex, $res, $matches);

		$xmlObj = simplexml_load_string(htmlspecialchars_decode($matches[2]));
		$xmlJson = json_encode($xmlObj);

		return json_decode($xmlJson, true);
	}

	public function getResponseResults($res) {
		$regex = '/\<LoadXMLResult\>(.*)\<\/LoadXMLResult\>/i';
		preg_match($regex, $res, $matches);

		$xmlObj = simplexml_load_string(htmlspecialchars_decode($matches[1]));
		$xmlJson = json_encode($xmlObj);

		return json_decode($xmlJson, true);
	}

	private function fillXML($xmlFile, $params, $sender = false) {

		// go over permaFields
		$xmlFile = $this->fillPermaFields($xmlFile, $params['permaFields']);

		// go over requiredFields
		if ($sender) {
			$xmlFile = $this->fillRequiredFields($xmlFile, $params['requiredFields']);
		}

		return $xmlFile;
	}

	private function fillRequiredFields($xmlFile, $params) {
		$requiredFields = '';
		$refDoc = '<RefDoc>';
		$refDocCloseTag = '</RefDoc>';
		$recDoc = '<Recorded>';
		$recDocCloseTag = '</Recorded>';
		foreach ($params as $key => $value) {
			$openTag = '<' . $key . '>';
			$closeTag = '</' . $key . '>';
			$emptyTag = '<' . $key . '/>';

			switch ($key) {
				case 'Grantor':
					$requiredFields .= '<Grantor><LastName>' . $value . '</LastName></Grantor>';
					break;
				case 'Grantee':
					$requiredFields .= '<Grantee><LastName>' . $value . '</LastName></Grantee>';
					break;
				case 'Assignee':
					$requiredFields .= '<Assignee><LastName>' . $value . '</LastName></Assignee>';
					break;
				case 'Assignor':
					$requiredFields .= '<Assignor><LastName>' . $value . '</LastName></Assignor>';
					break;
				case 'OriginalBeneficiary':
					$requiredFields .= '<OriginalBeneficiary><LastName>' . $value . '</LastName></OriginalBeneficiary>';
					break;
				case 'CurrentBeneficiary':
					$requiredFields .= '<CurrentBeneficiary><LastName>' . $value . '</LastName></CurrentBeneficiary>';
					break;
				case 'OriginalBorrower':
					$requiredFields .= '<OriginalBorrower><LastName>' . $value . '</LastName></OriginalBorrower>';
					break;
				case 'OriginalTrustee':
					$requiredFields .= '<OriginalTrustee><LastName>' . $value . '</LastName></OriginalTrustee>';
					break;
				case 'OriginalBorrower':
					$requiredFields .= '<OriginalBorrower><LastName>' . $value . '</LastName></OriginalBorrower>';
					break;
				case 'ReRecordingDate':
					$recDoc .= '<ReRecordingDate>' . $value . '</ReRecordingDate>';
					break;
				case 'ReDeedBook':
					$recDoc .= '<ReDeedBook>' . $value . '</ReDeedBook>';
					break;
				case 'ReInstrumentNo':
					$recDoc .= '<ReInstrumentNo>' . $value . '</ReInstrumentNo>';
					break;
				case 'ReDeedPage':
					$recDoc .= '<ReDeedPage>' . $value . '</ReDeedPage>';
					break;
				default:
					if ($value == '') {
						$requiredFields .= '';
					} else if (preg_match('/RefDoc/', $key)) {
						if ($key == 'RefDocReRecordingDate') {
							$refDoc .= '<RefDocReRecordingDate1>' . $value . '</RefDocReRecordingDate1>';
						} else if ($key == 'RefDocReDeedBook') {
							$refDoc .= '<RefDocReDeedBook1>' . $value . '</RefDocReDeedBook1>';
						} else if ($key == 'RefDocReDeedPage') {
							$refDoc .= '<RefDocReDeedPage1>' . $value . '</RefDocReDeedPage1>';
						} else if ($key == 'RefDocReInstrumentNo') {
							$refDoc .= '<RefDocReInstrumentNo1>' . $value . '</RefDocReInstrumentNo1>';
						} else {
							$refDoc .= $openTag . $value . $closeTag;
						}
					} else {
						$requiredFields .= $openTag . $value . $closeTag;
					}
					break;
			}
		}

		$refDoc .= $refDocCloseTag;
		$recDoc .= $recDocCloseTag;
		$requiredFields .= $refDoc;

		$xmlFile = str_replace('%requiredFields%', $requiredFields, $xmlFile);

		return $xmlFile;
	}

	private function fillPermaFields($xmlFile, $params) {
		$attachField = '';

		foreach($params as $key=>$value) {
			if (!!is_array($value)) {
				foreach($value as $index => $attachment) {
					$attachField .= '<Attachment type="' . $attachment['attachType'] . '">' . $attachment['attachment'] . '</Attachment>';
				}
			} else {
				$xmlFile = str_replace('%'.$key.'%', $value, $xmlFile);
			}
		}

		if ($attachField != '') {
			$xmlFile = str_replace('%attachmentFields%', $attachField, $xmlFile);
		}

		return $xmlFile;
	}

	public function getFileContent($fileData) {
		// make sure encryption is base64
		$provider = CredentialProvider::defaultProvider();

		$s3Client = new S3Client([
		  'region' => 'us-east-1',
		  'version' => 'latest',
		  'credentials' => $provider
		 ]);

		$result = $s3Client->getObject([
			'Bucket' => 'customer-submissions',
			'Key' => $this->getCompanyDomain() . '/originals/' . $fileData['fileName']
		]);

		$fileContent = (string)$result['Body'];
		$base64Doc = base64_encode(($fileContent)); // chunk_split?
		return $base64Doc;
	}

	public function updateDBRecord($fileId, $documentData, $formFields = array()) {
		global $pdoObj;
		$ticketId = $this->getTicketId();

		$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE subFileId = :id');
		$statement->execute([':id' => $fileId, ':status' => $documentData['packageStatus']]);

		// update attachment status as well
		foreach ($formFields['supportingDocuments'] as $key => $supportingDoc) {
			$supKeys = array_keys($supportingDoc);
			$statement = $pdoObj->prepare('UPDATE submission_files SET fileStatus = :status WHERE subFileId = :id');
			$statement->execute([':id' => $supportingDoc[$supKeys[0]], ':status' => $documentData['packageStatus']]);

			$statement = $pdoObj->prepare('UPDATE submission_attachments SET dateTransmitted = NOW() WHERE subId = :id');
			$statement->execute([':id' => $supportingDoc[$supKeys[0]]]);
		}

		$statement = $pdoObj->prepare('INSERT INTO csc_erecordings (subFileId, submissionId, companyId, cscUID, cscTrackingId, xmlDoc, cscStatus, cscReceipt, dateTransmitted, dateAdded) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())');
		$statement->execute([$fileId, $ticketId, $this->getCompanyId(), $documentData['uid'], $documentData['trackingID'], json_encode($this->xmlParams), $documentData['packageStatus']]);
	}

	public function getAverageTime($fileId) {
		global $pdoObj;
		// get average time from csc response table
		$statement = $pdoObj->prepare('SELECT cscAvgTime FROM csc_erecordings WHERE subFileId = :id');
		$statement->execute([':id' => $fileId]);
		$response = $statement->fetch();

		return $response['cscAvgTime'] > 0 ? $response['cscAvgTime'] : 'Immediate';
	}

	public function getNumFiles($data) {
		global $pdoObj;
		$count = 1;
		if ($data['hasAttachment']) {
			// get all files from submission_attachments table
			$statement = $pdoObj->prepare('SELECT COUNT(*) as numFiles FROM submission_attachments WHERE subId = :id');
			$statement->execute([':id' => $data['submissionId']]);
			$response = $statement->fetch();
			if ($response['numFiles'] > 0) {
				$count += $response['numFiles'];
			}
		}

		return $count;
	}

	public function getFileName($data) {
		global $pdoObj;

		// get most recent file id with submission
		// if attachment => get attachment file name as well
		$fileData = array(
		  'fileName' => '',
		  'attachment' => []
		 );

		$statement = $pdoObj->prepare('SELECT * FROM submission_files WHERE subFileId = :id ORDER BY subFileId DESC');
		$statement->execute([':id' => $data['subFileId']]);
		$response = $statement->fetchAll();

		$fileData['fileName'] = $response[0]['fileName'];

		if ($data['hasAttachment']) {
			// get all files from submission_attachments table
			$statement = $pdoObj->prepare('SELECT * FROM submission_attachments WHERE subId = :id');
			$statement->execute([':id' => $data['submissionId']]);
			$response = $statement->fetchAll();
			foreach ($response as $key => $attachment) {
				$fileData['attachment'][] = $attachment['fileName'];
			}
		}

		return $fileData;
	}

	public function buildReceipt($fileId, $documentResp) {
		// proof of submission content
		// Ticket ID | name | county | document Type | status | pages | estimated fees
		// build receipt in HTML then turn into PDF Form
		$submissionData = $this->getSubmissionData();

		// get submissionProof HTML
		$receiptFile = json_decode(file_get_contents("./files/submissionProof.json"), true);
		$receiptHTML = $receiptFile['data'];

		$confirmationFileName = 'Proof Of Submission_(TicketID_' . $this->getTicketId() . ')';

		$documentData['county'] = $submissionData['county'];
		$documentData['ticketId'] = $this->getTicketId();
		$documentData['dateTransmitted'] = $documentResp['dateTransmitted'];
		$documentData['name'] = $submissionData['firstName'] . ' ' . $submissionData['lastName'];
		$documentData['avgTime'] = $this->getAverageTime($fileId);
		$documentData['fee'] = $submissionData['estimatedFees'];

		// $filesInfo = $this->getFileName($submissionData);
		$documentData['fileName'] = $documentResp['fileName'];

		foreach($documentData as $key=>$value) {
			$receiptHTML = str_replace('%'.$key.'%', $value, $receiptHTML);
		}

		$dompdf = new Dompdf(array('enable_remote' => true));
		$dompdf->loadHtml($receiptHTML);
		$dompdf->render();
		$output = $dompdf->output();

		$documentData['fileName'] = $confirmationFileName . ".pdf";

		return array(
		  'receiptData' => $documentData,
		  'file' => $output
		 );
	}

	public function storeDocumentProof($receiptData) {
		// TODO: post to S3 receipt PDF and get path
		$provider = CredentialProvider::defaultProvider();

		$s3Client = new S3Client([
		  'region' => 'us-east-1',
		  'version' => 'latest',
		  'credentials' => $provider
		 ]);

		$result = $s3Client->putObject(array(
			'Bucket' => 'customer-submissions',
			'Key' => $this->getCompanyDomain() . '/csc/receipts/' . $receiptData['receiptData']['fileName'],
			'Body' => $receiptData['file']
		));

		$receiptData['receiptData']['path'] = $this->getCompanyDomain() . '/csc/receipts/' . $receiptData['receiptData']['fileName'];

		// send proof of receipt to customer email
		// $ticketId = $this->getTicketId();
		// $submissionData = $this->getSubmissionData($ticketId);

		// $this->sendProofNotification($submissionData, $receiptData['file'], $receiptData['receiptData']);

		return array(
			'dateTransmitted' => date("m/d/Y"),
			'receiptData' => $receiptData['receiptData'],
		);
	}

	public function getFileData($fileId) {
		global $pdoObj;

		$statement = $pdoObj->prepare('SELECT * FROM submission_files WHERE subFileId = :id');
		$statement->execute([':id' => $fileId]);
		$data = $statement->fetch();

		return $data;
	}

	public function getNewFileData($newFile) {
		var_dump($newFile); die;
	}

	public function sendProofNotification($data, $file, $receiptData) {

		$mimeEncoding = 'application/pdf';
		$attachment = PostmarkAttachment::fromRawData($file, $receiptData['fileName'], $mimeEncoding);

		$detailsArr = array(
		  'email' => $data['email'],
		  'county' => $data['county'],
		  'ticketId' => $this->getTicketId(),
		  'dateTransmitted' => $receiptData['dateTransmitted'],
		  'date' => date("m/d/Y"),
		  'name' => $data['firstName'] . ' ' . $data['lastName'],
		  'attachments' => array(
		  	$attachment
		  )
		 );

		$emailObj = new EmailHelper($this->getCompanyId(), NULL, NULL, 'notification', 'proofOfSubmission', NULL, $detailsArr);
		$emailObj->sendEmail([$attachment]);
	}

	private function getCSCUsername() {
		return ''; //replace with real API key
	}

	private function getCSCPass() {
		return ''; //replace with real user ID
	}

	private function getCSCDomain() {
		return '';
	}

	private function getSubmissionData() {
		global $pdoObj;

		$statement = $pdoObj->prepare('SELECT * FROM customers_submissions WHERE submissionId = :id');
		$statement->execute([':id' => $this->getTicketId()]);
		$response = $statement->fetch();

		return $response;
	}

	public function getCRQUsername() {
		return '';
	}

	public function getCRQPass() {
		return '';
	}

	private function getCountyCode($county) {
		return 'City of Providence';
	}

	private function setSCSBaseUrl() {
		$this->SCSUrl = '';
	}

	public function setPackageID($id) {
		$this->packageId = $id;
	}

	public function setDocumentID($id) {
		$this->documentId = $id;
	}

	public function getBaseURL() {
		return $this->SCSUrl;
	}

	public function setCompanyId($id) {
		$this->companyId = $id;
	}

	public function setCompanyDomain() {
		// get domain from subdomain table -> use Subdomain class
		$domainObj = Subdomain::getDomainObjFromId($this->getCompanyId());
		$this->companyDomain = $domainObj->domainAlias;
	}

	public function getCompanyDomain() {
		return $this->companyDomain;
	}

	public function setTicketId($id) {
		$this->ticketId = $id;
	}

	public function getCompanyId() {
		return $this->companyId;
	}

	public function getTicketId() {
		return $this->ticketId;
	}

	public function getPackageID() {
		return $this->packageId;
	}

	public function getDocumentID() {
		return $this->documentId;
	}

	public function getSocketServer() {
		return $this->socketURL;
	}
}