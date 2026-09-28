<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class StatisticsSessionDocument
{
    public function __invoke(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            $name = trim(preg_replace('#^/?api/#', '', $path->path), '/');
            foreach ($path->operations as $op) {
                if (! in_array($name, ['login', 'health'], true)) {
                    $op->description .= ' Web login tokens expire after 120 seconds without explicit user activity, including super admin. Ordinary reads, polling and writes do not renew. SESSION_IDLE_EXPIRED returns 401; expired tokens cannot be revived. Responses private, no-store.';
                }
                if (str_starts_with($name, 'statistics')) {
                    $op->parameters = array_values(array_filter($op->parameters, fn ($p) => ! in_array($p->name, ['facility_id', 'from_month', 'to_month'], true)));
                    foreach (['facility_id', 'from_month', 'to_month'] as $field) {
                        $op->addParameters([Parameter::make($field, 'query')->required(true)->setSchema(Schema::fromType($field === 'facility_id' ? (new IntegerType)->setMin(1) : new StringType))->example($field === 'facility_id' ? 1 : '2020-01')]);
                    }
                    $op->requestBodyObject = null; // These query parameters work for GET and POST export.
                    $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                    $op->description .= ' Anonymous statistics: active account, api ability and statistics.view in explicit facility_id; export additionally requires statistics.export and rechecks authority before releasing bytes. Required from_month/to_month YYYY-MM: 1–12 completed calendar months in facility timezone, year >=1900. No other filters or drilldown. data contains facility name/timezone, filters, privacy policy, monthly sections (key,title,definition,suppressed,patients,events,rows[label,patients,events]), occupancy unavailable. Distinct patients per metric/month; events are stored nonvoid rows. Visits include draft and complete by actual visit_date. Diagnoses/services/procedures require complete parent visits and actual event dates. Minimum five distinct patients per cell; small categories pooled without component labels, plus another category when necessary. Below-five cohort has null totals and no rows. No patient identifiers or raw clinical data. PDF/XLSX serialize this same protected projection, never raw rows. Fixed limits reject rather than truncate; export audited.';
                    foreach ([401 => 'Unauthenticated or idle-expired', 403 => 'Permission/account/facility denied', 422 => 'Invalid closed month range, disallowed filters or report limit'] as $status => $description) {
                        $op->addResponse(Response::make($status)->setDescription($description));
                    }
                }
                if (in_array($name, ['session', 'session/activity'], true)) {
                    $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                    $op->description .= ' GET session reads idle_timeout, remaining_seconds, server_time and expires_at without extending. POST session/activity is reserved for explicit interaction, atomically checks expiry before extending 120 seconds. No body required. Operator-issued noninteractive tokens return idle_timeout:null; login cannot request this exemption. Each device token independent; account freeze revokes all.';
                    $op->addResponse(Response::make(401)->setDescription('SESSION_IDLE_EXPIRED; no revival'));
                }
            }
        }
    }
}
