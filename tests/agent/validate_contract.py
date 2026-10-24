"""Validate OpenAPI 3.1 and actual synthetic PHP responses. No service calls."""
import argparse
import json
import os
import subprocess
import tempfile
from pathlib import Path
from jsonschema import Draft202012Validator, FormatChecker
from openapi_spec_validator import validate

parser = argparse.ArgumentParser()
parser.add_argument('--mysql-responses', type=Path)
parser.add_argument('--php', default='php')
parser.add_argument('--library-path', help='Optional macOS bottle library directories')
args = parser.parse_args()
env = dict(os.environ)
if args.library_path:
    env['DYLD_LIBRARY_PATH'] = args.library_path

root = Path(__file__).resolve().parents[2]
contract = json.loads((root / 'docs/agent-api/openapi.json').read_text())
validate(contract)
with tempfile.TemporaryDirectory(prefix='ggs-contract-') as directory:
    export = Path(directory) / 'responses.json'
    subprocess.run([args.php, '-n', str(root / 'tests/agent/run.php'), str(export)], env=env, check=True)
    responses = json.loads(export.read_text())
    for response in responses:
        path = '/' + response['route'] + '.php'
        method = response.get('method', 'get')
        schema = contract['paths'][path][method]['responses'][str(response['status'])]['content']['application/json']['schema']
        # Root local refs are resolved against the contract components.
        schema = dict(schema, components=contract['components'])
        Draft202012Validator(schema, format_checker=FormatChecker()).validate(response['body'])
print(f'PASS: OpenAPI 3.1; {len(responses)} synthetic responses conform to the contract.')

examples = json.loads((root / 'docs/agent-api/examples.json').read_text())
for name, body in examples.items():
    schema_name = 'ErrorResponse' if name == 'unauthorized' else name.title().replace('_', '') + 'Response'
    schema = {'$ref': '#/components/schemas/' + schema_name, 'components': contract['components']}
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(body)
print(f'PASS: {len(examples)} documented synthetic examples conform to the contract.')

draft_requests = {
    'ProductDraftCreate': {'slug':'synthetic-product','name_en':'Synthetic product','name_ro':'Produs sintetic',
        'category':'automotive','price_eur':'19.95','images':[],'specs':[]},
    'ProductDraftPatch': {'id':'42','expected_revision':'a'*64,'name_en':'Revised synthetic product'},
}
for name, body in draft_requests.items():
    schema = {'$ref':'#/components/schemas/' + name, 'components':contract['components']}
    Draft202012Validator(schema, format_checker=FormatChecker()).validate(body)
print(f'PASS: {len(draft_requests)} draft write request examples conform to the contract.')

if args.mysql_responses:
    mysql_responses = json.loads(args.mysql_responses.read_text())
    for response in mysql_responses:
        method = response.get('method', 'get')
        schema = contract['paths']['/' + response['route'] + '.php'][method]['responses'][str(response['status'])]['content']['application/json']['schema']
        Draft202012Validator(dict(schema, components=contract['components']), format_checker=FormatChecker()).validate(response['body'])
    print(f'PASS: {len(mysql_responses)} real database synthetic responses conform to the contract.')
