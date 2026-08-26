namespace PrintParade.Bridge.Worker;

public sealed class NullBridgeStatusSink : IBridgeStatusSink
{
    public void Started(Uri serverUrl) { }

    public void Connected(long bridgeId) { }

    public void Polled(bool processedJob) { }

    public void Warning(string message) { }

    public void Stopped() { }
}
