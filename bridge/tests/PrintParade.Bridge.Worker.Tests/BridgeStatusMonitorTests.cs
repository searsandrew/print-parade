using PrintParade.Bridge.Worker;

namespace PrintParade.Bridge.Worker.Tests;

public sealed class BridgeStatusMonitorTests
{
    [Fact]
    public async Task ServicePublishesStatusThatTheMonitorCanReadWithoutRunningAWorker()
    {
        var directory = Path.Combine(Path.GetTempPath(), $"print-parade-{Guid.NewGuid():N}");
        var path = Path.Combine(directory, "bridge-status.json");

        try
        {
            var sink = new FileBridgeStatusSink(path);
            sink.Started(new Uri("https://print.pacb.online"));
            sink.Connected(42);
            sink.Polled(processedJob: true);
            sink.Warning("Temporary network failure");

            var snapshot = await FileBridgeStatusSink.ReadAsync(path);
            using var output = new StringWriter();
            BridgeStatusMonitor.Render(snapshot, output);
            var display = output.ToString();

            Assert.NotNull(snapshot);
            Assert.Equal(42, snapshot.BridgeId);
            Assert.Equal(1, snapshot.JobsProcessed);
            Assert.Equal(1, snapshot.WarningCount);
            Assert.Contains("PRINT PARADE BRIDGE SERVICE MONITOR", display);
            Assert.Contains("https://print.pacb.online/", display);
            Assert.Contains("Bridge ID:       42", display);
            Assert.Contains("Temporary network failure", display);
            Assert.Contains("This monitor is read-only", display);
        }
        finally
        {
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, recursive: true);
            }
        }
    }
}
