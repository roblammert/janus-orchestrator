from . import file_writer_executor, http_executor, script_executor

EXECUTOR_REGISTRY = {
    "HTTP": http_executor.run,
    "SCRIPT": script_executor.run,
    "FILE_WRITER": file_writer_executor.run,
}
