import json
from pathlib import Path


def test_workflow_definition_schema_has_required_fields() -> None:
    schema_path = Path("01-Docs/workflow-definition.schema.json")
    schema = json.loads(schema_path.read_text(encoding="utf-8"))

    required = set(schema["required"])
    assert {"name", "version", "nodes", "edges"}.issubset(required)


def test_node_type_enum_contains_mvp_executors() -> None:
    schema_path = Path("01-Docs/workflow-definition.schema.json")
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    node_type_enum = set(schema["properties"]["nodes"]["items"]["properties"]["type"]["enum"])

    assert {"HTTP", "SCRIPT", "FILE_WRITER"} == node_type_enum
