using PrintParade.Bridge.Worker;

namespace PrintParade.Bridge.Worker.Tests;

public sealed class ConsoleBridgeStatusSinkTests
{
    [Fact]
    public void MonitorDisplaysConnectionPollingJobAndWarningState()
    {
        var originalOutput = Console.Out;
        using var output = new StringWriter();

        try
        {
            Console.SetOut(output);
            var monitor = new ConsoleBridgeStatusSink();

            monitor.Started(new Uri("https://print.pacb.online"));
            monitor.Connected(42);
            monitor.Polled(processedJob: true);
            monitor.Warning("Temporary network failure");

            var display = output.ToString();

            Assert.Contains("PRINT PARADE BRIDGE MONITOR", display);
            Assert.Contains("https://print.pacb.online/", display);
            Assert.Contains("Bridge ID:       42", display);
            Assert.Contains("Jobs processed:  1", display);
            Assert.Contains("Warnings:        1", display);
            Assert.Contains("Temporary network failure", display);
        }
        finally
        {
            Console.SetOut(originalOutput);
        }
    }
}
